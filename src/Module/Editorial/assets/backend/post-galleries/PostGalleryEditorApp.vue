<script setup>
/**
 * One publication's gallery, and nothing else about it.
 *
 * No heading of its own: the publication's name is already in the topbar and in
 * the breadcrumb, and a third copy beside the back button said nothing the two
 * above it had not.
 *
 * Composes `PostGalleryPanel` - the very panel the full editor draws in its
 * gallery tab - so there is one gallery editor in the application and not two
 * that drift. What is missing here is the point: no title, no status, no
 * taxonomy, no SEO. A contributor holding `editorial.posts.gallery` can arrange
 * pictures and write what they show.
 *
 * The screen not offering those fields is the courtesy. The guarantee is
 * `PostGalleryInput`, which cannot express them, and `PostGalleryManager`, which
 * writes two columns - so this file being wrong could only ever be a worse
 * screen, never a wider permission.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { ArrowLeft, Save } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import PostGalleryPanel from "../posts/components/PostGalleryPanel.vue";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

const props = defineProps({
    /** `{ id, galleryLayout, gallery }` - the arrangement and the words per locale. */
    post: { type: Object, required: true },
    locales: { type: Array, default: () => [] },
    updatePath: { type: String, required: true },
    listPath: { type: String, required: true },
});

const { t } = useI18n();
const { request } = useRequest();

/**
 * The editable copy.
 *
 * A copy and not the prop: `PostGalleryPanel` writes into what it is handed - the
 * composable owns the write - and mutating a prop is what `vue/no-mutating-props`
 * refuses. Every locale is seeded even when the server sent nothing for it, so
 * switching to an untranslated language gives the panel an object to write into
 * rather than `undefined`.
 */
const form = ref({
    galleryLayout: { ...(props.post.galleryLayout ?? {}) },
    gallery: Object.fromEntries(
        props.locales.map((code) => [code, { ...(props.post.gallery?.[code] ?? {}) }]),
    ),
});

const locale = ref(props.locales[0] ?? "en");

/**
 * The open locale's words.
 *
 * The arrangement is one per publication and the words are one set per language,
 * so switching the tab swaps the second and leaves the first standing - the same
 * split the full editor makes, and the reason the panel takes them separately.
 */
const words = computed(() => form.value.gallery[locale.value]);

const saving = ref(false);
const saved = ref(false);

async function save() {
    saving.value = true;
    saved.value = false;

    try {
        const data = await request(props.updatePath, {
            galleryLayout: form.value.galleryLayout,
            gallery: form.value.gallery,
        });

        // `useRequest` has already reported a failure. There are no field errors
        // to show: the normaliser accepts what it can use and drops the rest, so
        // there is no version of this form the server rejects field by field.
        if (!data) {
            return;
        }

        saved.value = true;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-3">
            <AppButton variant="ghost" size="sm" :href="listPath" class="shrink-0">
                <ArrowLeft class="h-4 w-4" :stroke-width="2" />
                {{ t("backend.post_galleries.back") }}
            </AppButton>

            <span class="flex-1" />

            <!-- Says so once it has, and stops saying it the moment anything is
                 saved again. A permanent tick would still be there tomorrow. -->
            <span v-if="saved" class="text-xs text-emerald-500">
                {{ t("backend.post_galleries.saved") }}
            </span>

            <AppButton
                variant="primary"
                size="sm"
                :loading="saving"
                class="shrink-0"
                v-on:click="save"
            >
                <Save class="h-4 w-4" :stroke-width="2" />
                {{ t("shared.common.save") }}
            </AppButton>
        </div>

        <!-- Only when there is more than one language. A single-locale site would
             get a row of one tab, which is a control that cannot do anything. -->
        <nav v-if="locales.length > 1" class="flex items-center gap-1" :aria-label="t('backend.post_galleries.locales')">
            <AppTab
                v-for="code in locales"
                :key="code"
                size="sm"
                :active="locale === code"
                v-on:click="locale = code"
            >
                {{ code }}
            </AppTab>
        </nav>

        <PostGalleryPanel :layout="form.galleryLayout" :words="words" :locale="locale" />
    </div>
</template>
