import {
    ref,
    reactive,
    computed,
    onMounted,
    watch,
    provide,
    nextTick,
} from "vue";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { useSlugLock } from "@/shared/composables/form/useSlugLock.js";
import { useKeyboardShortcut } from "@/shared/composables/useKeyboardShortcut.js";
import { useConflictResolution } from "./useConflictResolution.js";
import { useRelatedSearch } from "./useRelatedSearch.js";
import { usePostSave } from "./usePostSave.js";
import { applyPostData } from "../utils/applyPostData.js";
import {
    PostStatus,
    POST_STATUS_VALUES,
} from "@editorial/shared/enums/postStatus.js";
import PostsListBlock from "@editorial/shared/editorjs/PostsListBlock.js";

/**
 * Orchestration layer for `PostEditor.vue`. Holds the entire reactive
 * post payload, all per-locale state, the Editor.js extra-tools dict,
 * the save / merge / revisions / templates / preview flows, and the
 * keyboard shortcut. Pure logic — no template binding, no UI-only refs.
 *
 * Keeps the SFC thin (~80 lines `<script setup>`) per the
 * `convention-sfc-thin-presentation` rule.
 */
export function usePostEditor({ props, emit, t }) {
    const { request: getRequest } = useRequest();

    const activeLocale = ref(props.locales[0] ?? "fr");
    // `fetching` is kept around for the template loader hook even though
    // the standalone page hydrates from `props.post` synchronously now.
    const fetching = ref(false);

    // Local mirror of the post id — updates after a successful create so
    // subsequent saves hit the update endpoint, not the create one. Kept
    // separately from `props.post` so the create→edit transition stays
    // reactive without re-mounting the editor.
    const postId = ref(props.post?.id ?? null);

    // ── Conflict-resolution (remote merge) ───────────────────────────
    const {
        version,
        baseTranslations,
        remotePost,
        remoteLoading,
        showMerge,
        mergeRemoteTranslations,
        snapshotBase,
        openRemoteVersion,
        closeRemoteVersion,
        openMerge,
        closeMerge,
    } = useConflictResolution({
        showPath: props.showPath,
        postId,
    });

    // ── Editor.js bridge ─────────────────────────────────────────────
    // Captured by the AppBlockEditor instance via `inject(...)`. We
    // call them when switching locale (flush), restoring a revision
    // (render), or applying a template (render).
    let flushEditor = null;
    let renderEditorBlocks = null;
    provide("registerEditorFlush", (fn) => {
        flushEditor = fn;
    });
    provide("registerEditorRender", (fn) => {
        renderEditorBlocks = fn;
    });

    function makeEmptyTranslation() {
        return {
            title: "",
            slug: "",
            blocks: [],
            metaTitle: "",
            metaDescription: "",
            customFields: {},
            ogImageMediaId: null,
            ogImageUrl: null,
            ogImageFocalPosition: "50% 50%",
            canonicalUrl: "",
            noindex: false,
            focusKeyword: "",
            jsonLd: null,
        };
    }

    const postTypeOptions = computed(() =>
        props.postTypes.map((pt) => ({
            value: String(pt.id),
            label: pt.label,
        })),
    );

    // Editor.js tools Editorial itself owns (postsList → depends on
    // Post types). Anything tied to a sibling module (Ecommerce's
    // productGrid, etc.) comes through `props.extraEditorTools`,
    // keeping Editorial free of cross-module imports.
    const editorExtraTools = computed(() => ({
        postsList: {
            class: PostsListBlock,
            config: {
                postTypes: props.postTypes,
                titleLabel: t("backend.editor.posts_list.title_label"),
                postTypeLabel: t("backend.editor.posts_list.post_type_label"),
                columnsLabel: t("backend.editor.posts_list.columns_label"),
                modeLabel: t("backend.editor.posts_list.mode_label"),
                modeAutoLabel: t("backend.editor.posts_list.mode_auto_label"),
                modeManualLabel: t(
                    "backend.editor.posts_list.mode_manual_label",
                ),
                perPageLabel: t("backend.editor.posts_list.per_page_label"),
                searchPlaceholderLabel: t(
                    "backend.editor.posts_list.search_placeholder_label",
                ),
                selectedLabel: t("backend.editor.posts_list.selected_label"),
                emptyLabel: t("backend.editor.posts_list.empty_label"),
                noResultsLabel: t("backend.editor.posts_list.no_results_label"),
            },
        },
        ...props.extraEditorTools,
    }));

    const statusOptions = computed(() =>
        POST_STATUS_VALUES.map((value) => ({
            value,
            label: t(`backend.posts.status_options.${value}`),
        })),
    );

    const form = reactive({
        postTypeId: String(props.postTypes[0]?.id ?? ""),
        status: PostStatus.Draft,
        scheduledAt: "",
        featuredMediaId: null,
        termIds: [],
        translations: Object.fromEntries(
            props.locales.map((locale) => [locale, makeEmptyTranslation()]),
        ),
        relatedPostIds: [],
        commentsEnabled: true,
        ...Object.fromEntries(
            Object.entries(props.extraFields ?? {}).map(([key, def]) => [
                key,
                def.default,
            ]),
        ),
    });

    const publishedAt = ref(null);
    const trashed = ref(false);
    const featuredMediaUrl = ref(null);
    const featuredMediaFocalPosition = ref("50% 50%");

    const activeTranslation = computed(
        () => form.translations[activeLocale.value] ?? null,
    );

    // ── Related posts ────────────────────────────────────────────────
    const {
        query: relatedSearchQuery,
        results: relatedSearchResults,
        loading: relatedSearchLoading,
        open: relatedSearchOpen,
        selected: relatedPosts,
        add: addRelatedPost,
        remove: removeRelatedPost,
        setSelected: setRelatedPosts,
    } = useRelatedSearch({
        excludeId: postId.value,
        getSelectedIds: () => form.relatedPostIds,
        addId: (id) => form.relatedPostIds.push(id),
        removeId: (id) => {
            form.relatedPostIds = form.relatedPostIds.filter(
                (existing) => existing !== id,
            );
        },
    });

    // ── Slug lock ────────────────────────────────────────────────────
    const { locked: slugLocked, toggle: toggleSlugLock } = useSlugLock({
        getTitle: () => form.translations[activeLocale.value]?.title ?? "",
        setSlug: (value) => {
            const tr = form.translations[activeLocale.value];
            if (tr) tr.slug = value;
        },
    });

    async function switchLocale(locale) {
        if (locale === activeLocale.value) return;
        await flushEditor?.();
        activeLocale.value = locale;
    }

    // ── Dirty state ──────────────────────────────────────────────────
    const isDirty = ref(false);
    watch(
        form,
        () => {
            isDirty.value = true;
        },
        { deep: true },
    );

    // Bag of refs forwarded to applyPostData to receive side-channel
    // values that live outside the form (publishedAt, trashed, …).
    const sideState = {
        publishedAt,
        trashed,
        featuredMediaUrl,
        featuredMediaFocalPosition,
        relatedPosts,
        version,
    };

    // ── Boot: hydrate from server-rendered payload ───────────────────
    // The standalone edit page (`/{id}/edit`) ships the post serialized
    // server-side in `props.post`. No client-side fetch needed.
    onMounted(() => {
        if (!props.post) return;
        applyPostData(props.post, form, sideState);
        setRelatedPosts(props.post.relatedPosts ?? []);
        for (const [key, def] of Object.entries(props.extraFields ?? {})) {
            form[key] = def.fromEntity
                ? def.fromEntity(props.post)
                : (props.post[key] ?? def.default);
        }
        snapshotBase(form.translations);
        nextTick(() => {
            isDirty.value = false;
        });
    });

    function toggleTerm(termId) {
        const index = form.termIds.indexOf(termId);
        if (index === -1) {
            form.termIds.push(termId);
        } else {
            form.termIds.splice(index, 1);
        }
    }

    // ── Taxonomy picker / custom fields (per current PostType) ───────
    const availableTaxonomies = computed(() => {
        const currentPostTypeId = Number(form.postTypeId);
        if (!currentPostTypeId) return [];
        return (props.taxonomies ?? []).filter((tx) =>
            (tx.postTypeIds ?? []).includes(currentPostTypeId),
        );
    });

    const customFieldsDefs = computed(() => {
        const currentPostTypeId = Number(form.postTypeId);
        const pt = props.postTypes.find(
            (postType) => postType.id === currentPostTypeId,
        );
        if (!pt) return [];
        return [...(pt.fields ?? [])].sort((a, b) => a.position - b.position);
    });

    const currentPostTypeSlug = computed(() => {
        const currentPostTypeId = Number(form.postTypeId);
        const pt = props.postTypes.find(
            (postType) => postType.id === currentPostTypeId,
        );
        return pt?.slug ?? "";
    });

    const frontUrl = computed(() => {
        const slug = form.translations[activeLocale.value]?.slug;
        if (!slug || !currentPostTypeSlug.value) return null;
        return `/${activeLocale.value}/${currentPostTypeSlug.value}/${slug}`;
    });

    function defaultValueForField(field) {
        if (field.type === "checkbox") return false;
        if (field.type === "reference" && field.options?.multiple === true)
            return [];
        return null;
    }

    function ensureCustomFieldsForLocale(locale) {
        const translation = form.translations[locale];
        if (!translation) return;
        for (const field of customFieldsDefs.value) {
            if (!(field.name in translation.customFields)) {
                translation.customFields[field.name] =
                    defaultValueForField(field);
            }
        }
    }

    watch(
        [customFieldsDefs, activeLocale],
        () => ensureCustomFieldsForLocale(activeLocale.value),
        { immediate: true },
    );

    // ── Overlays (preview, templates, revisions) ─────────────────────
    const showPreview = ref(false);
    const showTemplates = ref(false);
    const showRevisions = ref(false);
    const editorKey = ref(0);

    async function reloadAfterRestore() {
        showRevisions.value = false;
        if (!postId.value) return;
        const data = await getRequest(
            buildPath(props.showPath, { id: postId.value }),
            null,
            HttpMethod.Get,
        );
        if (!data || !data.success) return;
        applyPostData(data.post, form, sideState);
        setRelatedPosts(data.post.relatedPosts ?? []);
        snapshotBase(form.translations);
        if (
            renderEditorBlocks &&
            form.translations[activeLocale.value]?.blocks
        ) {
            await nextTick();
            await renderEditorBlocks(
                form.translations[activeLocale.value].blocks,
            );
        } else {
            editorKey.value++;
        }
        nextTick(() => {
            isDirty.value = false;
        });
    }

    async function applyTemplate(template) {
        const blocks = structuredClone(template.blocks);
        showTemplates.value = false;
        if (renderEditorBlocks) {
            await renderEditorBlocks(blocks);
        } else {
            form.translations[activeLocale.value].blocks = blocks;
            editorKey.value++;
        }
    }

    function openPreview() {
        if (isDirty.value) toast.info(t("backend.posts.preview_saved_hint"));
        showPreview.value = true;
    }

    const previewPost = computed(() => {
        if (!postId.value) return null;
        const postType = props.postTypes.find(
            (pt) => pt.id === Number(form.postTypeId),
        );
        return {
            id: postId.value,
            title: form.translations[activeLocale.value]?.title ?? "",
            slug: form.translations[activeLocale.value]?.slug ?? "",
            status: form.status,
            trashed: trashed.value,
            translations: form.translations,
            postType: postType
                ? {
                      id: postType.id,
                      slug: postType.slug,
                      label: postType.label,
                  }
                : null,
        };
    });

    // ── Save flow ────────────────────────────────────────────────────
    const {
        loading,
        errors,
        conflict,
        save: savePost,
    } = usePostSave(props.createPath, props.updatePath, (post) => {
        const wasNew = !postId.value;
        toast.success(
            wasNew ? t("backend.posts.created") : t("backend.posts.updated"),
        );
        version.value = post.version ?? null;
        snapshotBase(form.translations);
        // After a create the URL still says `/backend/editorial/posts/new`; swap
        // it for `/backend/editorial/posts/{id}/edit` without reloading so the
        // browser back/refresh land on the right page.
        if (wasNew && post.id) {
            postId.value = post.id;
            window.history.replaceState(
                {},
                "",
                props.editPath.replace("__id__", String(post.id)),
            );
        }
        emit("saved", post, wasNew);
    });

    async function handleSave({ force = false } = {}) {
        await flushEditor?.();
        const success = await savePost(postId.value, {
            postTypeId: Number(form.postTypeId),
            status: form.status,
            scheduledAt:
                form.status === PostStatus.Scheduled && form.scheduledAt
                    ? form.scheduledAt
                    : null,
            featuredMediaId: form.featuredMediaId,
            termIds: form.termIds,
            relatedPostIds: form.relatedPostIds,
            commentsEnabled: form.commentsEnabled,
            translations: form.translations,
            version: version.value,
            force,
        });
        if (success) isDirty.value = false;
    }

    async function applyMergeResolution(resolvedBlocksByLocale) {
        for (const [locale, blocks] of Object.entries(resolvedBlocksByLocale)) {
            if (form.translations[locale]) {
                form.translations[locale].blocks = blocks;
            }
        }
        closeMerge();
        await nextTick();
        if (
            renderEditorBlocks &&
            form.translations[activeLocale.value]?.blocks
        ) {
            await renderEditorBlocks(
                form.translations[activeLocale.value].blocks,
            );
        }
        await handleSave({ force: true });
    }

    function forceSave() {
        handleSave({ force: true });
    }

    // Ctrl+S anywhere on the page triggers a save — handler binds to
    // the live closure so it picks up the current form state.
    useKeyboardShortcut({ key: "s", ctrl: true }, () => handleSave());

    return {
        // Identity
        postId,
        // Loading / locale
        activeLocale,
        fetching,
        switchLocale,
        // Form
        form,
        activeTranslation,
        publishedAt,
        trashed,
        featuredMediaUrl,
        featuredMediaFocalPosition,
        isDirty,
        // Options
        postTypeOptions,
        statusOptions,
        availableTaxonomies,
        customFieldsDefs,
        currentPostTypeSlug,
        frontUrl,
        // Editor.js extras
        editorExtraTools,
        editorKey,
        // Conflict resolution
        version,
        baseTranslations,
        remotePost,
        remoteLoading,
        showMerge,
        mergeRemoteTranslations,
        openRemoteVersion,
        closeRemoteVersion,
        closeMerge,
        // Related posts
        relatedSearchQuery,
        relatedSearchResults,
        relatedSearchLoading,
        relatedSearchOpen,
        relatedPosts,
        addRelatedPost,
        removeRelatedPost,
        // Slug lock
        slugLocked,
        toggleSlugLock,
        // Terms
        toggleTerm,
        // Overlays
        showPreview,
        showTemplates,
        showRevisions,
        openPreview,
        previewPost,
        applyTemplate,
        reloadAfterRestore,
        // Save
        loading,
        errors,
        conflict,
        handleSave,
        forceSave,
        applyMergeResolution,
    };
}
