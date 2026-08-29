<script setup>
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { usePostEditor } from "./composables/usePostEditor.js";
import { useTabState } from "@/shared/composables/useTabState.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppFocalPointField from "@/shared/components/form/file/AppFocalPointField.vue";
import AppImagePickerField from "@/shared/components/form/file/AppImagePickerField.vue";
import PostBannerPanel from "./components/PostBannerPanel.vue";
import PostGridPanel from "./components/PostGridPanel.vue";
import PostGalleryPanel from "./components/PostGalleryPanel.vue";
import { Save, ArrowLeft, AlertTriangle, RefreshCw } from "lucide-vue-next";

const { t } = useI18n();

const props = defineProps({
    post: { type: Object, default: null },
    postTypes: { type: Array, default: () => [] },
    taxonomies: { type: Array, default: () => [] },
    locales: { type: Array, default: () => [] },
    statusOptions: { type: Array, default: () => [] },
    createPath: { type: String, required: true },
    updatePathTemplate: { type: String, required: true },
    editPathTemplate: { type: String, required: true },
    listPath: { type: String, required: true },
    bannerPreviewPath: { type: String, required: true },
    gridPreviewPath: { type: String, required: true },
    searchPath: { type: String, required: true },
    previewPathTemplate: { type: String, default: "" },
});

const {
    form, locale, current, errors, saving, conflict, postId,
    availableTaxonomies, supportsBlocks, supportsThumbnail, customFieldDefinitions,
    switchLocale, save, saveAnyway, reloadFromServer, toggleTerm, setCustomField,
} = usePostEditor(props);

const { request } = useRequest();

/**
 * Sections, in one order, and the first one is the one that opens.
 *
 * Settings leads because it is where the publication says what it is: its
 * title and summary sit at the top of it, and those name the record in the
 * admin list and on any card that embeds it. Arriving anywhere else would mean
 * arriving on a screen that never says which publication is being edited. The
 * rest follows the published page - header, then body - and ends with the
 * metadata only a machine reads.
 *
 * Position and default used to be set apart, on the grounds that position says
 * how the sections relate and the default says where the work is. That held
 * while the title was pinned above the tabs and visible from every one of
 * them. It stopped holding when the title moved into a section, so there is
 * one order now and `useTabState` falls back to its first key.
 *
 * There is one editor per post: a remembered key would be shared by all of
 * them, and two browser tabs on two posts would fight over it. The fragment
 * belongs to the page being looked at, survives the reload, and makes a link
 * to a specific section shareable.
 *
 * English keys because they end up in the URL, where the rest of the routing
 * is English too. The labels are translated; the identifier is not.
 */
const TABS = ["settings", "header", "content", "gallery", "seo"];
const { activeTab, select: selectTab, isActive: isTabActive } = useTabState(TABS, {
    hash: true,
});

const postTypeOptions = props.postTypes.map((type) => ({ value: type.id, label: type.label }));
const statusSelectOptions = props.statusOptions.map((status) => ({
    value: status,
    label: t(`backend.posts.status.${status}`),
}));

// Mirrors the list's own palette, so a post reads the same in both places.
const STATUS_COLORS = {
    draft: "gray",
    pending_review: "amber",
    scheduled: "sky",
    published: "emerald",
    archived: "zinc",
};

/**
 * What a `post` zone may link to. The editor already receives the related
 * publications it can reference, which is the same list - asking the server
 * for a second one would be a query for a list it already sent.
 */
/**
 * How the thumbnail fills a card's frame. Written out rather than assembled:
 * Tailwind only emits classes it can read in the source.
 */
const THUMBNAIL_FITS = ["cover", "contain", "fill"];
const THUMBNAIL_FIT_CLASSES = {
    cover: "object-cover",
    contain: "object-contain",
    fill: "object-fill",
};

/**
 * The gallery's words for every language, as the panel wants them.
 *
 * Assembled rather than stored that way, because the editor's `form` is organised
 * by translation - a locale holds its title, its SEO and its gallery words
 * together, which is right for everything except the gallery. The objects
 * themselves are passed through, not copied, so what the panel writes lands in the
 * translation it belongs to.
 */
const galleryWordsByLocale = computed(() =>
    Object.fromEntries(
        props.locales.map((code) => [code, form.value.translations[code]?.gallery ?? {}]),
    ),
);

const previewing = ref(false);

/**
 * Opens the page as it will look, in another tab.
 *
 * Saved first, deliberately. A preview of the last save is a preview of something
 * the author is not looking at, and the whole reason to press this is to see what
 * is on screen now.
 *
 * The tab is opened *before* the request rather than after it, because a
 * `window.open` that follows an await is a pop-up the browser did not see the
 * click for, and it gets blocked. It is pointed at the address once there is one,
 * and closed if the request failed.
 */
async function openPreview() {
    if (previewing.value) {
        return;
    }

    previewing.value = true;
    const tab = window.open("", "_blank", "noopener");

    try {
        await save(false);

        const data = await request(props.previewPathTemplate.replace("__id__", String(postId.value)));

        if (!data?.url) {
            tab?.close();

            return;
        }

        if (null === tab) {
            // Blocked anyway, or opened from somewhere that refuses. The address is
            // still good, so send them to it rather than losing the click.
            window.location.href = data.url;

            return;
        }

        tab.location.href = data.url;
    } finally {
        previewing.value = false;
    }
}

const thumbnailFitOptions = computed(() =>
    THUMBNAIL_FITS.map((fit) => ({
        value: fit,
        label: t(`backend.posts.thumbnail_fits.${fit}`),
    })),
);

// The preview crops the way a card will, so what is aimed at is what lands.
const thumbnailFitClass = computed(
    () => THUMBNAIL_FIT_CLASSES[form.value.thumbnailFit] ?? "object-cover",
);

const relatedPostOptions = computed(() =>
    (props.post?.relatedPosts ?? []).map((related) => ({
        id: related.id,
        title: related.title,
    })),
);

function termLabel(term) {
    return term.translations?.[props.locales[0]]?.name ?? `#${term.id}`;
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between gap-3">
            <AppButton variant="ghost" size="md" :href="listPath">
                <ArrowLeft class="w-4 h-4" :stroke-width="2" /> {{ t("backend.posts.back_to_list") }}
            </AppButton>
            <div class="flex items-center gap-3">
                <!-- Status stays visible whatever section is open. Knowing you
                     are editing a live page should not require opening a tab. -->
                <AppBadge :color="STATUS_COLORS[form.status] ?? 'gray'">
                    {{ t(`backend.posts.status.${form.status}`) }}
                </AppBadge>
                <!-- Only once the post exists: a preview needs an id, and offering
                     it on a form that has not saved yet would be a button that
                     cannot work. -->
                <AppButton
                    v-if="postId"
                    variant="secondary"
                    size="md"
                    :loading="previewing"
                    v-on:click="openPreview"
                >
                    <Eye class="w-4 h-4" :stroke-width="2" /> {{ t("backend.posts.preview.open") }}
                </AppButton>
                <AppButton variant="primary" size="md" :loading="saving" v-on:click="save(false)">
                    <Save class="w-4 h-4" :stroke-width="2" /> {{ t("shared.common.save") }}
                </AppButton>
            </div>
        </div>

        <div v-if="locales.length > 1" class="inline-flex p-1 bg-surface-2 border border-line rounded-lg gap-1">
            <AppTab
                v-for="code in locales"
                :key="code"
                size="sm"
                :active="locale === code"
                active-class="bg-surface text-primary shadow-sm"
                inactive-class="text-secondary hover:text-primary"
                v-on:click="switchLocale(code)"
            >
                {{ code }}
            </AppTab>
        </div>

        <div>
            <section class="space-y-4">
                <div class="flex gap-1 border-b border-line">
                    <AppTab
                        v-for="tab in TABS"
                        :key="tab"
                        variant="underline"
                        :active="isTabActive(tab)"
                        v-on:click="selectTab(tab)"
                    >
                        {{ t(`backend.posts.tabs.${tab}`) }}
                    </AppTab>
                </div>

                <!-- v-show, not v-if: the block editor holds its own state,
                     and remounting it per tab would throw away the undo stack
                     and flicker - the same reason one instance serves every
                     locale. -->
                <div v-show="isTabActive('header')" class="space-y-4">
                    <div class="bg-surface border border-line rounded-xl p-5 space-y-4">
                        <h3 class="text-sm font-semibold text-primary">{{ t("backend.posts.banner.title") }}</h3>
                        <!-- Two halves: the design is one per post, the words
                             are one set per language. Switching the locale tab
                             swaps the second and leaves the first standing. -->
                        <PostBannerPanel
                            :layout="form.bannerLayout"
                            :texts="current.banner"
                            :locale="locale"
                            :preview-path="bannerPreviewPath"
                        />
                    </div>
                </div>

                <div v-show="isTabActive('content')" class="space-y-4">
                    <!-- The grid is the body now, not one of two ways to write
                         one. The plain block column that used to sit under this
                         was migrated into a single full-width text zone - which
                         is what it always was - and the two paths it cost to
                         keep are down to one. `supportsBlocks` still names the
                         post types that have a body at all; only what it opens
                         has changed. -->
                    <div v-if="supportsBlocks" class="bg-surface border border-line rounded-xl p-5 space-y-3">
                        <h3 class="text-sm font-semibold text-primary">{{ t("backend.posts.grid.title") }}</h3>
                        <PostGridPanel
                            :layout="form.gridLayout"
                            :content="current.grid"
                            :locale="locale"
                            :post-options="relatedPostOptions"
                            :preview-path="gridPreviewPath"
                        />
                    </div>

                    <div v-if="customFieldDefinitions.length" class="bg-surface border border-line rounded-xl p-5 space-y-4">
                        <h3 class="text-sm font-semibold text-primary">{{ t("backend.posts.custom_fields") }}</h3>
                        <template v-for="field in customFieldDefinitions" :key="field.id">
                            <AppCheckbox
                                v-if="field.type === 'checkbox'"
                                :model-value="!!current.customFields[field.name]"
                                :label="field.label"
                                v-on:update:model-value="setCustomField(field.name, $event)"
                            />
                            <AppSelect
                                v-else-if="field.type === 'select'"
                                :model-value="current.customFields[field.name] ?? null"
                                :label="field.label"
                                :options="(field.options?.choices ?? []).map((choice) => ({ value: choice, label: choice }))"
                                v-on:update:model-value="setCustomField(field.name, $event)"
                            />
                            <AppTextarea
                                v-else-if="field.type === 'textarea'"
                                :model-value="current.customFields[field.name] ?? ''"
                                :label="field.label"
                                :placeholder="field.options?.placeholder ?? t('shared.placeholders.value')"
                                :rows="3"
                                v-on:update:model-value="setCustomField(field.name, $event)"
                            />
                            <AppInput
                                v-else
                                :model-value="current.customFields[field.name] ?? ''"
                                :label="field.label"
                                :placeholder="field.options?.placeholder ?? t('shared.placeholders.value')"
                                :type="field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'"
                                :required="field.required"
                                v-on:update:model-value="setCustomField(field.name, $event)"
                            />
                        </template>
                    </div>
                </div>

                <div v-show="isTabActive('settings')" class="space-y-4">
                    <!-- The record's own name and summary. They are not part of
                         Content, and never were: they identify the publication
                         in the admin list and on any card that embeds it, and
                         they head the page itself only as a fallback - an
                         in-page header carrying a title of its own takes over.
                         They sit at the top of Settings, above the slug they
                         are the source of. -->
                    <div class="bg-surface border border-line rounded-xl p-5 space-y-4">
                        <AppInput
                            v-model="current.title"
                            :label="t('backend.posts.field_title')"
                            :placeholder="t('shared.placeholders.title')"
                            :error="errors.title"
                        />
                        <AppTextarea
                            v-model="current.description"
                            :label="t('backend.posts.field_description')"
                            :placeholder="t('shared.placeholders.description')"
                            :rows="2"
                        />
                        <p class="text-xs text-muted">{{ t("backend.posts.identity_hint") }}</p>

                        <!-- Shared across languages, unlike the two fields
                             above it: a page with a heading in French and none
                             in German would be two different pages. -->
                        <AppCheckbox
                            v-model="form.titleVisible"
                            :label="t('backend.posts.title_visible')"
                            :hint="t('backend.posts.title_visible_hint')"
                        />
                    </div>

                    <div class="bg-surface border border-line rounded-xl p-5 space-y-4">
                        <AppInput
                            v-model="current.slug"
                            :label="t('backend.posts.field_slug')"
                            :placeholder="t('shared.placeholders.slug')"
                            :hint="t('backend.posts.slug_hint')"
                        />
                        <AppSelect
                            v-model="form.postTypeId"
                            :label="t('backend.posts.field_post_type')"
                            :options="postTypeOptions"
                            :error="errors.postTypeId"
                        />
                        <AppSelect
                            v-model="form.status"
                            :label="t('backend.posts.field_status')"
                            :options="statusSelectOptions"
                            :error="errors.status"
                        />
                        <AppInput
                            v-if="form.status === 'scheduled'"
                            v-model="form.scheduledAt"
                            type="datetime-local"
                            :label="t('backend.posts.field_scheduled_at')"
                            :placeholder="t('backend.posts.scheduled_at_placeholder')"
                            :error="errors.scheduledAt"
                        />
                        <AppCheckbox v-model="form.commentsEnabled" :label="t('backend.posts.comments_enabled')" />
                    </div>

                    <div v-if="supportsThumbnail" class="bg-surface border border-line rounded-xl p-5 space-y-4">
                        <h3 class="text-sm font-semibold text-primary">{{ t("backend.posts.thumbnail") }}</h3>
                        <p class="text-xs text-muted">{{ t("backend.posts.thumbnail_hint") }}</p>

                        <AppImagePickerField
                            v-model="form.thumbnail"
                            :label="t('backend.posts.thumbnail_image')"
                        />

                        <template v-if="form.thumbnail?.url">
                            <AppSelect
                                v-model="form.thumbnailFit"
                                :label="t('backend.posts.thumbnail_fit')"
                                :options="thumbnailFitOptions"
                            />
                            <AppFocalPointField
                                :src="form.thumbnail.url"
                                :x="form.thumbnailFocalX"
                                :y="form.thumbnailFocalY"
                                :fit-class="thumbnailFitClass"
                                :inherited="post?.thumbnailFocalPosition ?? '50% 50%'"
                                :label="t('backend.posts.thumbnail_focal')"
                                :hint="t('backend.posts.thumbnail_focal_hint')"
                                v-on:update:x="form.thumbnailFocalX = $event"
                                v-on:update:y="form.thumbnailFocalY = $event"
                            />
                        </template>
                    </div>

                    <div v-if="availableTaxonomies.length" class="bg-surface border border-line rounded-xl p-5 space-y-3">
                        <h3 class="text-sm font-semibold text-primary">{{ t("backend.posts.terms") }}</h3>
                        <div v-for="taxonomy in availableTaxonomies" :key="taxonomy.id" class="space-y-1">
                            <p class="text-xs uppercase tracking-wide text-muted">
                                {{ taxonomy.translations?.[locales[0]]?.label ?? taxonomy.slug }}
                            </p>
                            <AppCheckbox
                                v-for="term in taxonomy.terms ?? []"
                                :key="term.id"
                                :model-value="form.termIds.includes(term.id)"
                                :label="termLabel(term)"
                                v-on:update:model-value="toggleTerm(term.id)"
                            />
                        </div>
                    </div>
                </div>

                <!-- Below the grid on the page, and after it here: the tab order is
                     the reading order, which is the only order an author can
                     check against what they will see. -->
                <div v-show="isTabActive('gallery')" class="space-y-4">
                    <PostGalleryPanel
                        :layout="form.galleryLayout"
                        :words-by-locale="galleryWordsByLocale"
                        :locales="locales"
                        :locale="locale"
                    />
                </div>

                <div v-show="isTabActive('seo')" class="space-y-4">
                    <div class="bg-surface border border-line rounded-xl p-5 space-y-4">
                        <h3 class="text-sm font-semibold text-primary">{{ t("backend.posts.seo") }}</h3>
                        <AppInput
                            v-model="current.metaTitle"
                            :label="t('backend.posts.meta_title')"
                            :placeholder="t('backend.posts.meta_title_placeholder')"
                        />
                        <AppTextarea
                            v-model="current.metaDescription"
                            :label="t('backend.posts.meta_description')"
                            :placeholder="t('backend.posts.meta_description_placeholder')"
                            :hint="t('backend.posts.meta_description_hint')"
                            :rows="2"
                        />
                        <AppInput
                            v-model="current.focusKeyword"
                            :label="t('backend.posts.focus_keyword')"
                            :placeholder="t('backend.posts.focus_keyword_placeholder')"
                        />
                        <AppInput
                            v-model="current.canonicalUrl"
                            :label="t('backend.posts.canonical_url')"
                            :placeholder="t('shared.placeholders.url')"
                        />
                        <AppCheckbox v-model="current.noindex" :label="t('backend.posts.noindex')" :hint="t('backend.posts.noindex_hint')" />
                    </div>
                </div>
            </section>
        </div>

        <AppModal
            :show="conflict"
            max-width="sm"
            :closeable="false"
            :title="t('backend.posts.conflict_title')"
            :icon="AlertTriangle"
            v-on:close="conflict = false"
        >
            <p class="text-sm text-primary">{{ t("backend.posts.conflict_body") }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="reloadFromServer">
                        <RefreshCw class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.posts.conflict_reload") }}
                    </AppButton>
                    <AppButton variant="danger" size="md" :loading="saving" v-on:click="saveAnyway">
                        <Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.posts.conflict_overwrite") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
