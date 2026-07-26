<script setup>
/* eslint-disable vue/no-mutating-props -- translation is owned by parent (PostEditor form) and mutated in-place via shared reactive reference */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { ImagePlus, X, Upload, Sparkles } from "lucide-vue-next";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppFilePickerButton from "@/shared/components/action/AppFilePickerButton.vue";
import AppMessage from "@/shared/components/feedback/AppMessage.vue";
import GoogleSerpPreview from "./GoogleSerpPreview.vue";
import { seoCounterClass } from "@/shared/utils/seo/seoCounter.js";
import { parseJsonLd, buildArticleJsonLd } from "@/shared/utils/seo/jsonLd.js";
import { useImageUpload } from "@/shared/composables/http/backend/useImageUpload.js";
import { openDocumentPicker } from "@shared/utils/documentPicker.js";
import { toast } from "vue-sonner";

const { t } = useI18n();

const props = defineProps({
    translation: { type: Object, required: true },
    locale: { type: String, required: true },
    postTypeSlug: { type: String, default: null },
    publishedAt: { type: String, default: null },
});

const metaTitleLength = computed(() => props.translation?.metaTitle?.length ?? 0);
const metaDescLength = computed(() => props.translation?.metaDescription?.length ?? 0);

function containsCI(haystack, needle) {
    if (!haystack || !needle) return false;
    return haystack.toLowerCase().includes(needle.toLowerCase());
}

const focusChecks = computed(() => {
    const tr = props.translation;
    const keyword = tr?.focusKeyword?.trim() ?? "";
    if (!keyword || !tr) return [];
    return [
        { key: "title", ok: containsCI(tr.title, keyword) },
        { key: "metaTitle", ok: containsCI(tr.metaTitle, keyword) },
        { key: "metaDescription", ok: containsCI(tr.metaDescription, keyword) },
        { key: "slug", ok: containsCI(tr.slug, keyword) },
    ];
});

// JSON-LD textarea ↔ translation.jsonLd sync
const jsonLdText = ref("");
const jsonLdError = ref(null);

watch(
    () => props.translation?.jsonLd,
    (value) => {
        jsonLdText.value = value ? JSON.stringify(value, null, 2) : "";
        jsonLdError.value = null;
    },
    { immediate: true },
);

watch(jsonLdText, (raw) => {
    const tr = props.translation;
    if (!tr) return;
    const { value, error, empty } = parseJsonLd(raw);
    if (empty) {
        tr.jsonLd = null;
        jsonLdError.value = null;
        return;
    }
    if (error === "not-object") {
        jsonLdError.value = t("backend.posts.seo.json_ld_must_be_object");
        return;
    }
    if (error) {
        jsonLdError.value = error;
        return;
    }
    tr.jsonLd = value;
    jsonLdError.value = null;
});

function generateArticleJsonLd() {
    const tr = props.translation;
    if (!tr) return;
    const template = buildArticleJsonLd({
        title: tr.title,
        description: tr.metaDescription,
        imageUrl: tr.ogImageUrl,
        datePublished: props.publishedAt,
    });
    tr.jsonLd = template;
    jsonLdText.value = JSON.stringify(template, null, 2);
    jsonLdError.value = null;
}

// OG image upload
const {
    uploading: uploadingOg,
    inputRef: ogInputRef,
    uploadFromEvent: uploadOgImage,
} = useImageUpload({
    onSuccess: ({ file, media }) => {
        const tr = props.translation;
        if (!tr) return;
        tr.ogImageMediaId = file?.id ?? null;
        tr.ogImageUrl = file?.url ?? null;
        tr.ogImageFocalPosition = media?.focalPositionCss ?? "50% 50%";
    },
    onError: () => toast.error(t("shared.common.error")),
});

function removeOgImage() {
    const tr = props.translation;
    if (!tr) return;
    tr.ogImageMediaId = null;
    tr.ogImageUrl = null;
}

async function selectOgFromLibrary() {
    const doc = await openDocumentPicker({ imagesOnly: true });
    const tr = props.translation;
    if (!doc || !tr) return;
    tr.ogImageMediaId = doc.id;
    tr.ogImageUrl = doc.fileUrl ?? doc.url;
    tr.ogImageFocalPosition = doc.focalPositionCss ?? "50% 50%";
}
</script>

<template>
    <div class="border-t border-line pt-4 space-y-4">
        <p class="text-xs font-semibold text-secondary uppercase tracking-wide">SEO</p>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="space-y-3">
                <div>
                    <AppInput
                        v-model="translation.metaTitle"
                        :label="t('backend.posts.meta_title')"
                    />
                    <p class="text-right text-xs mt-1" :class="seoCounterClass(metaTitleLength, 60)">
                        {{ metaTitleLength }}/60
                    </p>
                </div>
                <div>
                    <AppTextarea
                        v-model="translation.metaDescription"
                        :label="t('backend.posts.meta_description')"
                        :rows="3"
                    />
                    <p class="text-right text-xs mt-1" :class="seoCounterClass(metaDescLength, 160)">
                        {{ metaDescLength }}/160
                    </p>
                </div>
                <AppInput
                    v-model="translation.focusKeyword"
                    :label="t('backend.posts.seo.focus_keyword')"
                    :placeholder="t('backend.posts.seo.focus_keyword_placeholder')"
                />
                <AppInput
                    v-model="translation.canonicalUrl"
                    :label="t('backend.posts.seo.canonical_url')"
                    placeholder="https://example.com/..."
                />
                <AppCheckbox
                    v-model="translation.noindex"
                    :label="t('backend.posts.seo.noindex')"
                />
            </div>

            <div class="space-y-4">
                <div>
                    <p class="text-xs text-secondary uppercase tracking-wide mb-2">{{ t("backend.posts.seo.serp_preview") }}</p>
                    <GoogleSerpPreview
                        :title="translation.metaTitle || translation.title"
                        :description="translation.metaDescription"
                        :slug="translation.slug"
                        :locale="locale"
                        :post-type-slug="postTypeSlug"
                    />
                </div>

                <AppMessage v-if="focusChecks.length" variant="info">
                    <p class="font-medium mb-1">{{ t("backend.posts.seo.focus_checks_title") }}</p>
                    <ul class="space-y-0.5 text-xs">
                        <li v-for="check in focusChecks" :key="check.key" class="flex items-center gap-1.5">
                            <span v-if="check.ok" class="text-emerald-500">✓</span>
                            <span v-else class="text-rose-500">✗</span>
                            {{ t(`backend.posts.seo.focus_checks.${check.key}`) }}
                        </li>
                    </ul>
                </AppMessage>
            </div>
        </div>

        <div class="border-t border-line pt-4">
            <label class="block text-xs font-semibold text-secondary uppercase tracking-wide mb-2">
                {{ t("backend.posts.seo.og_image") }}
            </label>
            <div class="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-3">
                <div class="w-full h-40 sm:w-36 sm:h-20 rounded-md border border-line bg-surface-2 overflow-hidden shrink-0 flex items-center justify-center">
                    <img
                        v-if="translation.ogImageUrl"
                        :src="translation.ogImageUrl"
                        class="w-full h-full object-cover"
                        :style="{ objectPosition: translation.ogImageFocalPosition ?? '50% 50%' }"
                        alt=""
                    >
                    <ImagePlus v-else class="w-6 h-6 sm:w-5 sm:h-5 text-muted" :stroke-width="2" />
                </div>
                <div class="flex flex-col sm:flex-row sm:flex-wrap gap-2 min-w-0">
                    <AppFilePickerButton
                        ref="ogInputRef"
                        accept="image/*"
                        variant="secondary"
                        size="sm"
                        :loading="uploadingOg"
                        class="w-full sm:w-auto"
                        v-on:change="uploadOgImage"
                    >
                        <Upload class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.posts.seo.og_image_upload") }}
                    </AppFilePickerButton>
                    <AppButton variant="secondary" size="sm" class="w-full sm:w-auto" v-on:click="selectOgFromLibrary">
                        <ImagePlus class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.posts.select_from_library") }}
                    </AppButton>
                    <AppButton
                        v-if="translation.ogImageUrl"
                        variant="ghost"
                        size="sm"
                        class="w-full sm:w-auto"
                        v-on:click="removeOgImage"
                    >
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                    </AppButton>
                </div>
            </div>
        </div>

        <div class="border-t border-line pt-4 space-y-2">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                <label class="text-xs text-secondary uppercase tracking-wide">
                    {{ t("backend.posts.seo.json_ld") }}
                </label>
                <AppButton variant="secondary" size="sm" class="w-full sm:w-auto" v-on:click="generateArticleJsonLd">
                    <Sparkles class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.posts.seo.generate_article") }}
                </AppButton>
            </div>
            <AppTextarea
                v-model="jsonLdText"
                :placeholder="t('backend.posts.seo.json_ld_placeholder')"
                :rows="8"
                :error="jsonLdError ?? ''"
                mono
            />
        </div>
    </div>
</template>
