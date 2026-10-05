import { describe, expect, it } from "vitest";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import DocumentFamilyUsage from "./DocumentFamilyUsage.vue";

const i18n = createTestI18n({
    suite: {
        ged: {
            documents: {
                family: {
                    chip_original: "Original",
                    usage_none: "Aucune version de la famille n'est utilisée",
                    usage_types: {
                        other: "{count} usage | {count} usage | {count} usages",
                        editorial_post:
                            "{count} publication | {count} publication | {count} publications",
                        configuration_setting:
                            "{count} réglage du site | {count} réglage du site | {count} réglages du site",
                    },
                },
            },
        },
    },
});

function render(props) {
    return mount(DocumentFamilyUsage, { props, global: { plugins: [i18n] } });
}

describe("the family usage line", () => {
    it("names each used member and what draws it", () => {
        const wrapper = render({
            members: [
                {
                    id: 1,
                    original: true,
                    usageCount: 1,
                    usageByType: { "configuration.setting": 1 },
                },
                {
                    id: 2,
                    original: false,
                    label: "rouge",
                    usageCount: 2,
                    usageByType: { "editorial.post": 2 },
                },
                { id: 3, original: false, label: "jaune", usageCount: 0 },
            ],
        });
        const text = wrapper.text();
        const t = i18n.global.t;

        expect(text).toContain(t("suite.ged.documents.family.chip_original"));
        expect(text).toContain(
            t(
                "suite.ged.documents.family.usage_types.configuration_setting",
                { count: 1 },
                1,
            ),
        );
        expect(text).toContain("rouge");
        expect(text).toContain(
            t(
                "suite.ged.documents.family.usage_types.editorial_post",
                { count: 2 },
                2,
            ),
        );
        expect(text).not.toContain("jaune");
    });

    /** The alternates route returns documents, not chips: the original is known by id. */
    it("recognises the original by id in what the strip fetched", () => {
        const wrapper = render({
            originalId: 7,
            members: [
                {
                    id: 7,
                    title: "Carte",
                    usageCount: 1,
                    usageByType: { "studio.deck": 1 },
                },
                {
                    id: 8,
                    alternateLabel: "bleu",
                    usageCount: 0,
                    usageByType: [],
                },
            ],
        });

        expect(wrapper.text()).toContain(
            i18n.global.t("suite.ged.documents.family.chip_original"),
        );
    });

    it("falls back to a plain count for a kind it has no word for", () => {
        const wrapper = render({
            members: [
                {
                    id: 1,
                    original: true,
                    usageCount: 3,
                    usageByType: { "billing.invoice": 3 },
                },
            ],
        });

        expect(wrapper.text()).toContain("3 usages");
    });

    it("says so when no member is used", () => {
        const wrapper = render({
            members: [{ id: 1, original: true, usageCount: 0 }],
        });

        expect(wrapper.text()).toBe(
            i18n.global.t("suite.ged.documents.family.usage_none"),
        );
    });
});
