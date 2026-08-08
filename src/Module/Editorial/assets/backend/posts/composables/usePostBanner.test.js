import { describe, it, expect, vi } from "vitest";
import { ref, isRef } from "vue";
import { usePostBanner } from "./usePostBanner.js";

vi.mock("vue-i18n", () => ({
    useI18n: () => ({ t: (key) => key }),
}));

function makeBanner(overrides = {}) {
    return ref({
        enabled: true,
        height: "md",
        width: "contained",
        verticalAlign: "center",
        logoMediaId: null,
        logo: null,
        background: {
            type: "none",
            color: null,
            gradientFrom: null,
            gradientTo: null,
            gradientAngle: 135,
            overlay: 0,
            mediaId: null,
            media: null,
        },
        items: [],
        ...overrides,
    });
}

describe("usePostBanner", () => {
    /**
     * The composable shipped returning two option lists it never declared. The
     * panel threw on setup and rendered nothing at all — while the build, the
     * PHP tests and the linter were every one of them green, because no code
     * anywhere called this function.
     *
     * Reading every key back is what makes that impossible to repeat: a name in
     * the return object that has no declaration is a ReferenceError the moment
     * this runs.
     */
    it("exposes every name it returns", () => {
        const api = usePostBanner(makeBanner());

        for (const [key, value] of Object.entries(api)) {
            expect(value, `${key} is missing`).toBeDefined();
        }
    });

    it("builds every option list the panel binds to", () => {
        const api = usePostBanner(makeBanner());

        const lists = {
            heightOptions: 4,
            alignOptions: 3,
            fillOptions: 3,
            widthModeOptions: 3,
            verticalAlignOptions: 3,
            titleSizeOptions: 4,
            widthOptions: 6,
        };

        for (const [name, count] of Object.entries(lists)) {
            expect(isRef(api[name]), `${name} is not a ref`).toBe(true);
            expect(api[name].value, name).toHaveLength(count);
            for (const option of api[name].value) {
                expect(option.label, `${name} label`).toBeTruthy();
            }
        }
    });

    it("writes through to the banner rather than to a copy", () => {
        const banner = makeBanner();
        const { fields } = usePostBanner(banner);

        fields.height.value = "full";
        fields.verticalAlign.value = "end";
        fields.fillType.value = "solid";
        fields.backgroundColor.value = "#123456";

        expect(banner.value.height).toBe("full");
        expect(banner.value.verticalAlign).toBe("end");
        expect(banner.value.background.type).toBe("solid");
        expect(banner.value.background.color).toBe("#123456");
    });

    it("adds, reorders and removes items", () => {
        const banner = makeBanner();
        const { addItem, moveItem, removeItem, items } = usePostBanner(banner);

        addItem("text");
        addItem("image");
        expect(items.value.map((item) => item.type)).toEqual(["text", "image"]);

        moveItem(0, 1);
        expect(items.value.map((item) => item.type)).toEqual(["image", "text"]);

        removeItem(0);
        expect(items.value.map((item) => item.type)).toEqual(["text"]);
    });

    it("refuses to move an item past either end", () => {
        const banner = makeBanner();
        const { addItem, moveItem, items } = usePostBanner(banner);

        addItem("text");
        addItem("image");

        moveItem(0, -1);
        moveItem(1, 1);

        expect(items.value.map((item) => item.type)).toEqual(["text", "image"]);
    });

    it("stops adding items at the cap", () => {
        const banner = makeBanner();
        const { addItem, canAddItem, items } = usePostBanner(banner);

        for (let index = 0; index < 10; index += 1) {
            addItem("text");
        }

        expect(items.value).toHaveLength(6);
        expect(canAddItem.value).toBe(false);
    });

    it("exposes the per-item fields as writable computeds", () => {
        const banner = makeBanner();
        const { addItem, itemFields } = usePostBanner(banner);

        addItem("text");
        const item = itemFields(0);

        item.title.value = "Bonjour";
        item.titleSize.value = "xl";

        expect(banner.value.items[0].title).toBe("Bonjour");
        expect(banner.value.items[0].titleSize).toBe("xl");
    });

    it("previews a gradient only once both stops are set", () => {
        const banner = makeBanner();
        const { fields, fillPreviewStyle } = usePostBanner(banner);

        fields.fillType.value = "gradient";
        fields.gradientFrom.value = "#000000";
        expect(fillPreviewStyle.value).toBeNull();

        fields.gradientTo.value = "#ffffff";
        expect(fillPreviewStyle.value.backgroundImage).toContain(
            "linear-gradient(135deg, #000000, #ffffff)",
        );
    });
});
