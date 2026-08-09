import { describe, it, expect, vi } from "vitest";
import { usePostEditor } from "./usePostEditor.js";

vi.mock("vue-i18n", () => ({ useI18n: () => ({ t: (key) => key }) }));
vi.mock("vue-sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock("@/shared/composables/http/backend/useRequest.js", () => ({
    useRequest: () => ({ request: vi.fn() }),
}));
vi.mock("@/shared/composables/form/useServerErrors.js", () => ({
    useServerErrors: () => ({
        errors: {},
        clearErrors: vi.fn(),
        handleErrors: vi.fn(),
    }),
}));
// provide() needs a component instance; nothing here exercises the registry.
vi.mock("vue", async (importOriginal) => ({
    ...(await importOriginal()),
    provide: vi.fn(),
}));

const props = {
    post: null,
    postTypes: [{ id: 1 }],
    taxonomies: [],
    locales: ["fr", "en"],
    statusOptions: ["draft"],
    createPath: "/create",
    updatePathTemplate: "/{id}/update",
    editPathTemplate: "/{id}/edit",
    listPath: "/",
    bannerPreviewPath: "/preview",
    searchPath: "/search",
};

/**
 * The editor's form is where a field that reached the entity, the DTO, the
 * manager and the serialiser can still be missing — and be missing silently,
 * because a panel binding to `undefined` renders empty rather than throwing.
 * The banner shipped that way once already.
 */
describe("usePostEditor", () => {
    it("gives every locale a complete translation shape", () => {
        const { form } = usePostEditor(props);

        for (const locale of ["fr", "en"]) {
            const translation = form.value.translations[locale];

            expect(translation.banner.items, locale).toEqual({});
            expect(translation.grid.zones, locale).toEqual({});
            expect(translation.blocks, locale).toEqual([]);
        }
    });

    it("gives the post the two shared layouts", () => {
        const { form } = usePostEditor(props);

        expect(form.value.bannerLayout.items).toEqual([]);
        expect(form.value.gridLayout.zones).toEqual([]);
        expect(form.value.gridLayout.snap).toBe(4);
        expect(form.value.gridLayout.enabled).toBe(false);
    });

    /**
     * A post saved before either feature existed comes back with the field
     * absent, or as an empty array where an object is expected.
     */
    it("repairs a translation the server sent without them", () => {
        const { form } = usePostEditor({
            ...props,
            post: {
                id: 1,
                translations: { fr: { title: "Ancien", banner: [], grid: [] } },
            },
        });

        expect(form.value.translations.fr.banner.items).toEqual({});
        expect(form.value.translations.fr.grid.zones).toEqual({});
    });

    it("repairs a post the server sent without a grid", () => {
        const { form } = usePostEditor({
            ...props,
            post: { id: 1, translations: {} },
        });

        expect(form.value.gridLayout.zones).toEqual([]);
    });

    /** Both layouts sit on the form, so the save payload carries them. */
    it("ships the shared layouts in what gets saved", () => {
        const { form } = usePostEditor(props);

        expect(Object.keys(form.value)).toContain("bannerLayout");
        expect(Object.keys(form.value)).toContain("gridLayout");
    });
});
