import { describe, it, expect, vi } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import { ref } from "vue";

// useTheme accesses localStorage + window.matchMedia at module level - stub it
vi.mock("@/shared/composables/useTheme.js", () => ({
    useTheme: () => ({ theme: ref("light"), toggle: vi.fn() }),
}));

import AppDatePicker from "./AppDatePicker.vue";
import { VueDatePicker } from "@vuepic/vue-datepicker";

const i18n = createTestI18n({}, "en");

const globalConfig = {
    plugins: [i18n],
    stubs: { VueDatePicker: true },
};

// Un bouchon qui déclare les props qu'on veut lire : `stubs: true` les laisse
// tomber dans les attributs, et un objet y arrive illisible.
const withProbe = {
    plugins: [i18n],
    stubs: {
        VueDatePicker: {
            name: "VueDatePicker",
            props: ["textInput", "modelValue", "placeholder"],
            template: "<div />",
        },
    },
};

describe("AppDatePicker", () => {
    it("renders label text", () => {
        const wrapper = mount(AppDatePicker, {
            props: { label: "Birth date", modelValue: "" },
            global: globalConfig,
        });
        expect(wrapper.text()).toContain("Birth date");
    });

    it("shows required asterisk when required=true", () => {
        const wrapper = mount(AppDatePicker, {
            props: { label: "Date", required: true, modelValue: "" },
            global: globalConfig,
        });
        expect(wrapper.find("span.text-red-500").exists()).toBe(true);
    });

    it("renders error message when error prop is set", () => {
        const wrapper = mount(AppDatePicker, {
            props: { modelValue: "", error: "Invalid date" },
            global: globalConfig,
        });
        expect(wrapper.find("p.text-red-500").text()).toBe("Invalid date");
    });

    it("does not render error paragraph when error is empty", () => {
        const wrapper = mount(AppDatePicker, {
            props: { modelValue: "" },
            global: globalConfig,
        });
        expect(wrapper.find("p.text-red-500").exists()).toBe(false);
    });

    it("renders hint text under the control instead of leaking it as an attribute", () => {
        const wrapper = mount(AppDatePicker, {
            props: { modelValue: "", hint: "Leave empty to publish now" },
            global: globalConfig,
        });
        expect(wrapper.find("p.text-muted").text()).toBe(
            "Leave empty to publish now",
        );
        expect(wrapper.attributes("hint")).toBeUndefined();
    });

    it("renders the stubbed VueDatePicker component", () => {
        const wrapper = mount(AppDatePicker, {
            props: { modelValue: "" },
            global: globalConfig,
        });
        expect(wrapper.find("vue-date-picker-stub").exists()).toBe(true);
    });

    it("accepts a YYYY-MM modelValue in monthOnly mode without crashing", () => {
        // Smoke test: the YYYY-MM parser in the SFC's internalValue computed
        // shouldn't blow up on a 7-char value (the bug we'd otherwise hit by
        // shoving it through `new Date(...)` which gives a UTC midnight that
        // can shift the month in some timezones).
        const wrapper = mount(AppDatePicker, {
            props: { modelValue: "2026-05", monthOnly: true },
            global: globalConfig,
        });
        expect(wrapper.find("vue-date-picker-stub").exists()).toBe(true);
    });

    it("accepts a typed date, in the formats a person writes", async () => {
        // Le comportement, pas la prop : le champ ressemble à un champ de
        // texte, donc il se tape. Sans la saisie clavier le composant
        // n'écoutait que les clics, et une date tapée disparaissait à la
        // fermeture du calendrier sans un mot.
        const wrapper = mount(AppDatePicker, {
            props: { modelValue: "" },
            global: { plugins: [i18n] },
        });

        const input = wrapper.find("input");
        await input.setValue("15/11/2026");
        await input.trigger("keydown.enter");

        expect(wrapper.emitted("update:modelValue")?.at(-1)).toEqual([
            "2026-11-15",
        ]);
    });

    it("accepts the ISO form too", async () => {
        const wrapper = mount(AppDatePicker, {
            props: { modelValue: "" },
            global: { plugins: [i18n] },
        });

        const input = wrapper.find("input");
        await input.setValue("2026-11-15");
        await input.trigger("keydown.enter");

        expect(wrapper.emitted("update:modelValue")?.at(-1)).toEqual([
            "2026-11-15",
        ]);
    });

    describe("with a timeZone", () => {
        // Le serveur et l'ordinateur ne sont pas toujours dans le même fuseau :
        // une publication programmée pour 9 h doit sortir à 9 h à l'heure du
        // site. Les tests lisent les champs locaux, donc ils tiennent quel que
        // soit le fuseau de la machine qui les lance.
        const probe = { plugins: [i18n] };

        it("shows an instant at the zone's wall clock", () => {
            const wrapper = mount(AppDatePicker, {
                props: {
                    modelValue: "2026-10-02T07:00:00+00:00",
                    enableTime: true,
                    timeZone: "Europe/Paris",
                },
                global: probe,
            });

            const shown = wrapper
                .findComponent(VueDatePicker)
                .props("modelValue");
            expect([shown.getHours(), shown.getMinutes()]).toEqual([9, 0]);
        });

        it("hands back the typed time with the zone's offset", async () => {
            const wrapper = mount(AppDatePicker, {
                props: {
                    modelValue: "",
                    enableTime: true,
                    timeZone: "Europe/Paris",
                },
                global: probe,
            });
            const picker = wrapper.findComponent(VueDatePicker);

            picker.vm.$emit("update:model-value", new Date(2026, 9, 2, 9, 0));
            picker.vm.$emit("update:model-value", new Date(2026, 11, 1, 9, 0));

            expect(wrapper.emitted("update:modelValue")).toEqual([
                ["2026-10-02T09:00:00+02:00"],
                ["2026-12-01T09:00:00+01:00"],
            ]);
        });

        it("keeps the bare time without one", async () => {
            const wrapper = mount(AppDatePicker, {
                props: { modelValue: "", enableTime: true },
                global: probe,
            });

            wrapper
                .findComponent(VueDatePicker)
                .vm.$emit("update:model-value", new Date(2026, 9, 2, 9, 0));

            expect(wrapper.emitted("update:modelValue")).toEqual([
                ["2026-10-02T09:00"],
            ]);
        });
    });

    // Since version 12 the library reads the shown format from `formats.input`
    // and ignores `format` without a word: every date of the back office came
    // back as « 11/05/2026, 01:00 » for the 5th of November.
    it.each([
        [{ modelValue: "2026-11-05" }, "05/11/2026"],
        [
            { modelValue: "2026-11-05T09:30", enableTime: true },
            "05/11/2026 09:30",
        ],
        [{ modelValue: "2026-11", monthOnly: true }, "11/2026"],
    ])("shows the date the French way %o", async (props, expected) => {
        // Le vrai composant : la v12 ignore `format`, et un bouchon ne le
        // verrait pas. Ce qui compte est le texte affiché dans le champ.
        const wrapper = mount(AppDatePicker, {
            props,
            global: { plugins: [createTestI18n({}, "fr")] },
            attachTo: document.body,
        });
        await flushPromises();

        expect(wrapper.find("input").element.value).toBe(expected);
        wrapper.unmount();
    });
});
