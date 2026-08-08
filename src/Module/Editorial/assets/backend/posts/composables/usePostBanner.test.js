import { describe, it, expect, vi } from "vitest";
import { ref, isRef } from "vue";
import { usePostBanner } from "./usePostBanner.js";

vi.mock("vue-i18n", () => ({
    useI18n: () => ({ t: (key) => key }),
}));

function makeLayout(overrides = {}) {
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

function makeTexts(overrides = {}) {
    return ref({ items: {}, ...overrides });
}

/** The pair the composable takes, since the banner is stored in two halves. */
function make(overrides = {}) {
    const layout = makeLayout(overrides);
    const texts = makeTexts();

    return { layout, texts, api: usePostBanner(layout, texts) };
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
        const api = make().api;

        for (const [key, value] of Object.entries(api)) {
            expect(value, `${key} is missing`).toBeDefined();
        }
    });

    it("builds every option list the panel binds to", () => {
        const api = make().api;

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
        const { layout: banner, api } = make();
        const { fields } = api;

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
        const { addItem, moveItem, removeItem, items } = make().api;

        addItem("text");
        addItem("image");
        expect(items.value.map((item) => item.type)).toEqual(["text", "image"]);

        moveItem(0, 1);
        expect(items.value.map((item) => item.type)).toEqual(["image", "text"]);

        removeItem(0);
        expect(items.value.map((item) => item.type)).toEqual(["text"]);
    });

    it("refuses to move an item past either end", () => {
        const { addItem, moveItem, items } = make().api;

        addItem("text");
        addItem("image");

        moveItem(0, -1);
        moveItem(1, 1);

        expect(items.value.map((item) => item.type)).toEqual(["text", "image"]);
    });

    it("stops adding items at the cap", () => {
        const { addItem, canAddItem, items } = make().api;

        for (let index = 0; index < 10; index += 1) {
            addItem("text");
        }

        expect(items.value).toHaveLength(6);
        expect(canAddItem.value).toBe(false);
    });

    it("sends each field to the half it belongs to", () => {
        const { layout, texts, api } = make();
        const { addItem, itemFields } = api;

        addItem("text");
        const item = itemFields(0);

        item.title.value = "Bonjour";
        item.titleSize.value = "xl";

        const id = layout.value.items[0].id;

        // The word is translated, the size is not.
        expect(texts.value.items[id].title).toBe("Bonjour");
        expect(layout.value.items[0].titleSize).toBe("xl");
        expect(layout.value.items[0].title).toBeUndefined();
    });

    it("treats a button's link as copy, not as layout", () => {
        const { layout, texts, api } = make();
        const { addItem, itemFields } = api;

        addItem("button");
        itemFields(0).url.value = "/fr/contact";
        itemFields(0).buttonColor.value = "#ffffff";

        const id = layout.value.items[0].id;

        expect(texts.value.items[id].url).toBe("/fr/contact");
        expect(layout.value.items[0].buttonColor).toBe("#ffffff");
    });

    /**
     * The whole point of the split. Re-pointing `texts` is what switching the
     * locale tab does, and the design has to survive it untouched.
     */
    it("keeps the design when the language changes", () => {
        const layout = makeLayout();
        const french = makeTexts();
        const { addItem, itemFields } = usePostBanner(layout, french);

        addItem("text");
        itemFields(0).title.value = "Bonjour";
        const before = JSON.stringify(layout.value);

        // Same composable, a different set of words: what the editor hands it
        // after the tab changes.
        const german = makeTexts();
        const api = usePostBanner(layout, german);
        api.itemFields(0).title.value = "Guten Tag";

        expect(JSON.stringify(layout.value)).toBe(before);
        expect(french.value.items[layout.value.items[0].id].title).toBe(
            "Bonjour",
        );
        expect(german.value.items[layout.value.items[0].id].title).toBe(
            "Guten Tag",
        );
    });

    it("drops an item's text when the item goes", () => {
        const { layout, texts, api } = make();

        api.addItem("text");
        const id = layout.value.items[0].id;
        api.itemFields(0).title.value = "Bonjour";

        api.removeItem(0);

        expect(texts.value.items[id]).toBeUndefined();
    });

    /**
     * A counter would hand the next item the id of the one just deleted, and
     * every other language would greet it with the removed item's words.
     */
    it("never hands a new item the id of a removed one", () => {
        const { layout, api } = make();

        api.addItem("text");
        const first = layout.value.items[0].id;
        api.removeItem(0);
        api.addItem("text");

        expect(layout.value.items[0].id).not.toBe(first);
    });

    it("gives every item a distinct id", () => {
        const { layout, api } = make();

        for (let index = 0; index < 6; index += 1) {
            api.addItem("text");
        }

        const ids = layout.value.items.map((item) => item.id);
        expect(new Set(ids).size).toBe(6);
    });

    it("previews a gradient only once both stops are set", () => {
        const { fields, fillPreviewStyle } = make().api;

        fields.fillType.value = "gradient";
        fields.gradientFrom.value = "#000000";
        expect(fillPreviewStyle.value).toBeNull();

        fields.gradientTo.value = "#ffffff";
        expect(fillPreviewStyle.value.backgroundImage).toContain(
            "linear-gradient(135deg, #000000, #ffffff)",
        );
    });
});
