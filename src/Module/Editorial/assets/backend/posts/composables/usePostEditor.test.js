import { describe, it, expect, vi } from "vitest";
import { nextTick } from "vue";
import { usePostEditor } from "./usePostEditor.js";

vi.mock("vue-i18n", () => ({ useI18n: () => ({ t: (key) => key }) }));
vi.mock("vue-sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock("@/shared/composables/http/backend/useRequest.js", () => ({
    useRequest: () => ({
        request: vi.fn(async () => ({
            success: true,
            posts: [
                {
                    id: 1,
                    title: "Ce billet même",
                    status: "published",
                    postTypeId: 1,
                    postType: "Page",
                },
                {
                    id: 5,
                    title: "Stratégie de contenu",
                    status: "published",
                    postTypeId: 2,
                    postType: "Service",
                },
            ],
        })),
    }),
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
 * manager and the serialiser can still be missing - and be missing silently,
 * because a panel binding to `undefined` renders empty rather than throwing.
 * The banner shipped that way once already.
 */
describe("usePostEditor slug seeding", () => {
    // Le slug est une URL publique. Le deriver du titre fait gagner du temps a
    // la creation ; l'ecraser ensuite casserait un lien deja partage.

    it("derives the slug from the title while the slug is empty", async () => {
        const { form, current } = usePostEditor(props);

        current.value.title = "À propos";
        await nextTick();

        expect(form.value.translations.fr.slug).toBe("a-propos");
    });

    it("never overwrites a slug the user has already typed", async () => {
        const { form, current } = usePostEditor(props);

        current.value.slug = "mon-url-a-moi";
        current.value.title = "Un titre tout autre";
        await nextTick();

        expect(form.value.translations.fr.slug).toBe("mon-url-a-moi");
    });

    it("seeds each locale from its own title", async () => {
        const { form, current, locale } = usePostEditor(props);

        current.value.title = "À propos";
        await nextTick();

        locale.value = "en";
        await nextTick();

        current.value.title = "About";
        await nextTick();

        expect(form.value.translations.fr.slug).toBe("a-propos");
        expect(form.value.translations.en.slug).toBe("about");
    });

    it("does not invent a slug just because the language tab changed", async () => {
        const { form, current, locale } = usePostEditor(props);

        // La traduction anglaise a deja un titre, saisi ailleurs, et pas de slug.
        form.value.translations.en.title = "About";

        locale.value = "en";
        await nextTick();

        expect(form.value.translations.en.slug).toBe("");
        expect(current.value.title).toBe("About");
    });
});

describe("usePostEditor", () => {
    it("gives every locale a complete translation shape", () => {
        const { form } = usePostEditor(props);

        for (const locale of ["fr", "en"]) {
            const translation = form.value.translations[locale];

            expect(translation.banner.items, locale).toEqual({});
            expect(translation.grid.zones, locale).toEqual({});
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

/**
 * The Publication zone can only offer posts already in `relatedPostIds` - see
 * `PostGridZoneContent.vue`'s `publicationOptions`. Without a way to add to
 * that list, the zone's picker stayed empty forever: the field round-tripped
 * on save, but nothing in the editor ever wrote to it.
 */
describe("usePostEditor related posts", () => {
    it("starts with the titles the server already resolved", () => {
        const { selectedRelatedPosts } = usePostEditor({
            ...props,
            post: {
                id: 1,
                relatedPostIds: [5],
                relatedPosts: [{ id: 5, title: "Stratégie de contenu" }],
                translations: {},
            },
        });

        expect(selectedRelatedPosts.value).toEqual([
            { id: 5, title: "Stratégie de contenu" },
        ]);
    });

    it("adds a post found by search and clears the search", () => {
        const {
            form,
            selectedRelatedPosts,
            relatedPostSearch,
            addRelatedPost,
        } = usePostEditor(props);

        relatedPostSearch.value = "stratégie";
        addRelatedPost({ id: 5, title: "Stratégie de contenu" });

        expect(form.value.relatedPostIds).toEqual([5]);
        expect(selectedRelatedPosts.value).toEqual([
            { id: 5, title: "Stratégie de contenu" },
        ]);
        expect(relatedPostSearch.value).toBe("");
    });

    it("does not add the same post twice", () => {
        const { form, addRelatedPost } = usePostEditor(props);

        addRelatedPost({ id: 5, title: "Stratégie de contenu" });
        addRelatedPost({ id: 5, title: "Stratégie de contenu" });

        expect(form.value.relatedPostIds).toEqual([5]);
    });

    it("removes a post without touching the others", () => {
        const { form, removeRelatedPost } = usePostEditor({
            ...props,
            post: {
                id: 1,
                relatedPostIds: [5, 6],
                relatedPosts: [],
                translations: {},
            },
        });

        removeRelatedPost(5);

        expect(form.value.relatedPostIds).toEqual([6]);
    });

    it("searches the endpoint and excludes the post being edited from the results", async () => {
        const { relatedPostSearch, relatedPostSearchOptions } = usePostEditor({
            ...props,
            post: { id: 1, translations: {} },
        });

        relatedPostSearch.value = "stratégie";
        await nextTick();
        await nextTick();

        expect(
            relatedPostSearchOptions.value.map((option) => option.id),
        ).toEqual([5]);
    });

    it("clears the search results once the search box empties", async () => {
        const { relatedPostSearch, relatedPostSearchOptions } =
            usePostEditor(props);

        relatedPostSearch.value = "stratégie";
        await nextTick();
        await nextTick();
        relatedPostSearch.value = "";
        await nextTick();
        await nextTick();

        expect(relatedPostSearchOptions.value).toEqual([]);
    });
});

/**
 * Every one of these maps is keyed by an id and starts out empty, and PHP has
 * no way to write an empty map as anything but `[]`. Read back as a list, they
 * accept what is written into them and lose it at `JSON.stringify` - so what
 * the server sends is normalised on arrival rather than trusted.
 */
describe("usePostEditor keyed maps", () => {
    const withTranslation = (translation) => ({
        ...props,
        post: { id: 1, translations: { fr: translation } },
    });

    it("reads an empty map sent as an array as an empty map", () => {
        const { form } = usePostEditor(
            withTranslation({
                banner: { items: [] },
                grid: { zones: [] },
                gallery: { items: [] },
            }),
        );

        const fr = form.value.translations.fr;

        expect(fr.banner.items).toEqual({});
        expect(fr.grid.zones).toEqual({});
        expect(fr.gallery.items).toEqual({});
        expect(Array.isArray(fr.grid.zones)).toBe(false);
    });

    it("keeps a language's own banner background, preview included", () => {
        const { form } = usePostEditor(
            withTranslation({
                banner: {
                    items: {},
                    background: {
                        mediaId: 8,
                        mobileMediaId: null,
                        media: { url: "/fr.webp" },
                        mobileMedia: null,
                    },
                },
            }),
        );

        const background = form.value.translations.fr.banner.background;

        expect(background.mediaId).toBe(8);
        expect(background.media).toEqual({ url: "/fr.webp" });
        expect(background.mobileMediaId).toBeNull();
    });

    it("gives a translation saved before it an empty background", () => {
        const { form } = usePostEditor(
            withTranslation({ banner: { items: [] } }),
        );

        expect(form.value.translations.fr.banner.background).toEqual({
            mediaId: null,
            mobileMediaId: null,
            media: null,
            mobileMedia: null,
        });
    });

    it("keeps a map that actually holds something", () => {
        const { form } = usePostEditor(
            withTranslation({
                grid: { zones: { abc: { blocks: [], code: "echo;" } } },
            }),
        );

        expect(form.value.translations.fr.grid.zones.abc.code).toBe("echo;");
    });
});
