import { describe, it, expect, vi } from "vitest";
import { ref } from "vue";
import { mount } from "@vue/test-utils";
import PostGridZoneContent from "./PostGridZoneContent.vue";
import { usePostGrid } from "../composables/usePostGrid.js";

// The date picker reads the colour scheme when it is imported, which jsdom
// cannot answer; the panel is what is tested here, not the picker.
vi.mock("@/shared/components/form/picker/AppDatePicker.vue", () => ({
    default: {
        name: "AppDatePicker",
        props: ["modelValue"],
        template: "<div />",
    },
}));

vi.mock("vue-i18n", () => ({
    useI18n: () => ({ t: (key) => key }),
}));

/**
 * The settings panel of each zone that arrived with the new blocks: it
 * mounts, it shows the fields of that kind of zone, and a field writes to the
 * zone's `options` rather than to a key of its own.
 */
function panelFor(type) {
    const layout = ref({ enabled: true, snap: 4, zones: [] });
    const content = ref({ zones: {} });
    const api = usePostGrid(layout, content);

    api.addZone(type);
    const zone = layout.value.zones[0];

    const wrapper = mount(PostGridZoneContent, {
        props: {
            zone,
            fields: api.zoneFields(0),
            locale: "fr",
            choices: api.zoneChoices.value,
        },
        global: {
            stubs: {
                AppBlockEditor: true,
                AppImagePickerField: true,
                AppDatePicker: true,
            },
        },
    });

    return { wrapper, zone };
}

describe("PostGridZoneContent", () => {
    it.each([
        "availability",
        "openingHours",
        "countdown",
        "contactCard",
        "socialPost",
        "qrCode",
        "chart",
        "editorialCalendar",
        "activityFeed",
        "priceList",
        "poll",
    ])("offers the settings of a %s zone", (type) => {
        const { wrapper } = panelFor(type);

        expect(wrapper.html()).not.toBe("");
        expect(
            wrapper.findAll("input, textarea, select, button").length,
        ).toBeGreaterThan(0);
    });

    it("stores a day's opening hours as it understood them", async () => {
        const { wrapper, zone } = panelFor("openingHours");

        await wrapper.findAll("input")[0].setValue("9h-12h, 14h-18h");

        expect(zone.options.hours.mon).toEqual([
            ["09:00", "12:00"],
            ["14:00", "18:00"],
        ]);
    });

    it("keeps a business card's name under the zone's options", async () => {
        const { wrapper, zone } = panelFor("contactCard");

        await wrapper.findAll("input")[0].setValue("Axel Raboit");

        expect(zone.options.contactName).toBe("Axel Raboit");
    });
});
