import { computed, nextTick, provide, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { useServerErrors } from "@/shared/composables/form/useServerErrors.js";

// Mirrors BannerNormalizer's layout shape. A new post starts with no items:
// the banner is a list an author builds, not a pair of boxes to fill.
//
// The design only. It sits on the post, so every language shows the same
// banner and translating one means writing its words, not rebuilding it.
export function emptyBannerLayout() {
    return {
        enabled: false,
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
            gradientAngle: 180,
            mediaId: null,
            overlay: 0,
            media: null,
            fillStyle: null,
        },
        items: [],
    };
}

/** The words, keyed by the id of the layout item they belong to. */
export function emptyBannerTexts() {
    return { items: {} };
}

/**
 * The content grid's arrangement. Like the banner's, it sits on the post so
 * every language shows the same layout - see GridNormalizer for why each field
 * is on the side it is.
 */
export function emptyGridLayout() {
    return { enabled: false, snap: 4, zones: [] };
}

/** What fills each zone, keyed by the id of the zone on the post. */
export function emptyGridContent() {
    return { zones: {} };
}

/**
 * The gallery's arrangement. On the post like the two above: the same pictures
 * in the same order in every language - see GalleryNormalizer for the rest.
 */
export function emptyGalleryLayout() {
    return {
        enabled: false,
        layout: "grid",
        columns: 3,
        ratio: "natural",
        items: [],
    };
}

/** Alt text and captions, keyed by the id of the item on the post. */
export function emptyGalleryContent() {
    return { items: {} };
}

function emptyTranslation() {
    return {
        title: "",
        slug: "",
        description: "",
        banner: emptyBannerTexts(),
        grid: emptyGridContent(),
        gallery: emptyGalleryContent(),
        metaTitle: "",
        metaDescription: "",
        customFields: {},
        ogImageMediaId: null,
        canonicalUrl: "",
        noindex: false,
        focusKeyword: "",
        jsonLd: null,
    };
}

function translationFrom(source) {
    const translation = { ...emptyTranslation(), ...(source ?? {}) };

    // A translation saved before the split, or one the server sent as an empty
    // array, would leave `items` undefined and every text field unbindable.
    translation.banner = {
        items: translation.banner?.items ?? {},
    };

    // Same guard as the banner above: a translation saved before the grid
    // existed, or one the server sent as an empty array, would leave `zones`
    // undefined and every field unbindable.
    translation.grid = {
        zones: translation.grid?.zones ?? {},
    };

    // And the gallery, for the third time and the same reason: every post that
    // predates it sends nothing here, and an undefined `items` makes every alt
    // and caption field unbindable.
    translation.gallery = {
        items: translation.gallery?.items ?? {},
    };

    return translation;
}

export function usePostEditor(props) {
    const { t } = useI18n();
    const { request } = useRequest();
    const { errors, clearErrors, handleErrors } = useServerErrors();

    const postId = ref(props.post?.id ?? null);
    const version = ref(props.post?.version ?? null);
    const saving = ref(false);
    const conflict = ref(false);

    const form = ref({
        postTypeId: props.post?.postType?.id ?? props.postTypes[0]?.id ?? null,
        status: props.post?.status ?? "draft",
        scheduledAt: props.post?.scheduledAt ?? "",
        // The picker works in {id, url}; the wire format is just the id.
        thumbnail: {
            id: props.post?.thumbnailId ?? null,
            url: props.post?.thumbnailUrl ?? null,
        },
        thumbnailFit: props.post?.thumbnailFit ?? "cover",
        // Null means "use the point stored on the document itself".
        thumbnailFocalX: props.post?.thumbnailFocalX ?? null,
        thumbnailFocalY: props.post?.thumbnailFocalY ?? null,
        commentsEnabled: props.post?.commentsEnabled ?? true,
        // Whether the page prints its own title and summary. On the post like
        // the banner below, for the same reason: it is design, written once.
        titleVisible: props.post?.titleVisible ?? true,
        // On the post, beside status and terms, rather than inside a
        // translation: one design, shared by every language.
        bannerLayout: {
            ...emptyBannerLayout(),
            ...(props.post?.bannerLayout ?? {}),
        },
        gridLayout: {
            ...emptyGridLayout(),
            ...(props.post?.gridLayout ?? {}),
        },
        galleryLayout: {
            ...emptyGalleryLayout(),
            ...(props.post?.galleryLayout ?? {}),
        },
        termIds: [...(props.post?.termIds ?? [])],
        relatedPostIds: [...(props.post?.relatedPostIds ?? [])],
        translations: Object.fromEntries(
            props.locales.map((locale) => [
                locale,
                translationFrom(props.post?.translations?.[locale]),
            ]),
        ),
    });

    const locale = ref(props.locales[0] ?? "en");
    const current = computed(() => form.value.translations[locale.value]);

    const postType = computed(
        () =>
            props.postTypes.find((type) => type.id === form.value.postTypeId) ??
            null,
    );

    /** Only the taxonomies this type actually declares can be picked. */
    const availableTaxonomies = computed(() =>
        props.taxonomies.filter((taxonomy) =>
            (taxonomy.postTypeIds ?? []).includes(form.value.postTypeId),
        ),
    );

    // "blocks" is what a post type calls having a body, and it kept the name
    // when the body became a grid: it is a value stored on every post type, so
    // renaming it would be a migration for a word. What it opens has changed;
    // what it means has not.
    const supportsBlocks = computed(
        () => postType.value?.supports?.includes("blocks") ?? false,
    );
    const supportsThumbnail = computed(
        () => postType.value?.supports?.includes("thumbnail") ?? false,
    );
    const customFieldDefinitions = computed(() => postType.value?.fields ?? []);

    // One editor instance per locale, not one per tab: remounting loses the
    // undo stack and flickers. The parent drives them instead - flush what the
    // outgoing locale holds, then let each re-read what the incoming one does.
    //
    // A set rather than one callback. With the content grid a page can hold
    // several editors, and a single slot would have kept only the last to
    // mount - every other zone's text lost on save, silently.
    const editors = new Set();
    provide("registerEditor", (handlers) => {
        editors.add(handlers);

        return () => editors.delete(handlers);
    });

    async function flushEditors() {
        await Promise.all([...editors].map((editor) => editor.flush()));
    }

    async function switchLocale(next) {
        if (next === locale.value) return;

        await flushEditors();
        locale.value = next;
        // After the swap, so each one reads the incoming translation.
        await nextTick();
        await Promise.all([...editors].map((editor) => editor.render()));
    }

    function payload(force = false) {
        const { thumbnail, ...rest } = form.value;

        return {
            ...rest,
            thumbnailId: thumbnail?.id ?? null,
            scheduledAt: form.value.scheduledAt || null,
            version: version.value,
            force,
        };
    }

    async function save(force = false) {
        if (saving.value) return;

        // Each editor holds its own blocks in its own state; without this the
        // last edits before pressing save are simply not in the payload.
        await flushEditors();

        saving.value = true;
        clearErrors();
        try {
            const url = postId.value
                ? buildPath(props.updatePathTemplate, { id: postId.value })
                : props.createPath;

            const data = await request(url, payload(force));

            if (data?.conflict) {
                conflict.value = true;

                return;
            }

            if (!data?.success) {
                handleErrors(data?.errors);

                return;
            }

            const wasNew = null === postId.value;
            postId.value = data.post.id;
            version.value = data.post.version;
            conflict.value = false;
            toast.success(
                t(wasNew ? "backend.posts.created" : "backend.posts.updated"),
            );

            // A created post now has a URL of its own; swapping it in means a
            // refresh reopens what was just written rather than a blank form.
            if (wasNew) {
                window.history.replaceState(
                    {},
                    "",
                    buildPath(props.editPathTemplate, { id: data.post.id }),
                );
            }
        } finally {
            saving.value = false;
        }
    }

    /** Discards the other editor's version and saves this one over it. */
    async function saveAnyway() {
        conflict.value = false;
        await save(true);
    }

    async function reloadFromServer() {
        conflict.value = false;
        window.location.reload();
    }

    function toggleTerm(termId) {
        form.value.termIds = form.value.termIds.includes(termId)
            ? form.value.termIds.filter((id) => id !== termId)
            : [...form.value.termIds, termId];
    }

    function setCustomField(name, value) {
        current.value.customFields = {
            ...current.value.customFields,
            [name]: value,
        };
    }

    return {
        postId,
        version,
        form,
        locale,
        current,
        errors,
        saving,
        conflict,
        postType,
        availableTaxonomies,
        supportsBlocks,
        supportsThumbnail,
        customFieldDefinitions,
        switchLocale,
        save,
        saveAnyway,
        reloadFromServer,
        toggleTerm,
        setCustomField,
    };
}
