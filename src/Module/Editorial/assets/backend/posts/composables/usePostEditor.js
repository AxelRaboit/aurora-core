import { computed, provide, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { useServerErrors } from "@/shared/composables/form/useServerErrors.js";

// Mirrors BannerNormalizer's shape. A new translation starts with no items:
// the banner is a list an author builds, not a pair of boxes to fill.
export function emptyBanner() {
    return {
        enabled: false,
        height: "md",
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

function emptyTranslation() {
    return {
        title: "",
        slug: "",
        description: "",
        blocks: [],
        banner: emptyBanner(),
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
    return { ...emptyTranslation(), ...(source ?? {}) };
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
        featuredMedia: {
            id: props.post?.featuredMediaId ?? null,
            url: props.post?.featuredMediaUrl ?? null,
        },
        commentsEnabled: props.post?.commentsEnabled ?? true,
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

    const supportsBlocks = computed(
        () => postType.value?.supports?.includes("blocks") ?? false,
    );
    const supportsThumbnail = computed(
        () => postType.value?.supports?.includes("thumbnail") ?? false,
    );
    const customFieldDefinitions = computed(() => postType.value?.fields ?? []);

    // One editor instance for every locale: remounting it per tab loses the
    // undo stack and flickers. The parent drives it instead — flush what the
    // outgoing locale holds, render what the incoming one does.
    let flushEditor = null;
    let renderEditor = null;
    provide("registerEditorFlush", (fn) => {
        flushEditor = fn;
    });
    provide("registerEditorRender", (fn) => {
        renderEditor = fn;
    });

    async function switchLocale(next) {
        if (next === locale.value) return;

        if (flushEditor) await flushEditor();
        locale.value = next;
        if (renderEditor) await renderEditor(current.value.blocks);
    }

    function payload(force = false) {
        const { featuredMedia, ...rest } = form.value;

        return {
            ...rest,
            featuredMediaId: featuredMedia?.id ?? null,
            scheduledAt: form.value.scheduledAt || null,
            version: version.value,
            force,
        };
    }

    async function save(force = false) {
        if (saving.value) return;

        // The editor holds the current locale's blocks in its own state; without
        // this the last edits before pressing save are simply not in the payload.
        if (flushEditor) await flushEditor();

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
