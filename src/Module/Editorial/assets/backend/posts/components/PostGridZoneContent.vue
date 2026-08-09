<script setup>
/**
 * What fills one zone, whichever kind it is.
 *
 * Pulled out of `PostGridPanel` when stacks arrived, for the reason
 * `_grid_zone.html.twig` exists on the rendering side: a zone inside a stack
 * takes exactly the same fields as one on a row, and the alternative was these
 * seventy lines written twice and drifting apart on the first change.
 *
 * Presentation only. Every field arrives as a writable computed already bound
 * to the right half — shared on the post, or translated — so nothing here needs
 * to know that split exists.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import AppBlockEditor from "@/shared/components/editor/AppBlockEditor.vue";
import AppChoiceRow from "@/shared/components/form/select/AppChoiceRow.vue";
import AppImagePickerField from "@/shared/components/form/file/AppImagePickerField.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";

const props = defineProps({
    /** The zone itself — read for its type, never written to. */
    zone: { type: Object, required: true },
    /** Its writable computeds, from `usePostGrid().zoneFields(...)`. */
    fields: { type: Object, required: true },
    /** Which language the translated fields belong to. */
    locale: { type: String, required: true },
    /** Publications a `post` zone may name. */
    postOptions: { type: Array, default: () => [] },
    /** The shapes a media zone may be cropped to. */
    ratioOptions: { type: Array, default: () => [] },
});

const { t } = useI18n();

/**
 * The same bag of writable computeds, under a name of our own.
 *
 * `v-model="fields.blocks.value"` reads to ESLint as mutating a prop, and it is
 * not: the write goes through the computed's setter into the post or the
 * translation, and the `fields` object itself is never touched. Aliasing says
 * that, where a disable comment would only silence it.
 *
 * Safe because a zone's fields are cached per zone and each card is keyed by
 * zone id — a different zone is a different instance of this component, not the
 * same one handed a new object.
 */
const bound = props.fields;

const publicationOptions = computed(() =>
    props.postOptions.map((post) => ({ value: post.id, label: post.title ?? `#${post.id}` })),
);
</script>

<template>
    <div class="space-y-4">
        <template v-if="zone.type === 'text'">
            <div class="rounded-lg border border-dashed border-line p-3 space-y-2">
                <p class="text-xs uppercase tracking-wide text-muted">
                    {{ t("backend.posts.grid.translated_fields", { locale }) }}
                </p>
                <AppBlockEditor
                    v-model="bound.blocks.value"
                    :placeholder="t('backend.posts.content_placeholder')"
                />
            </div>
        </template>

        <template v-else-if="zone.type === 'media'">
            <AppImagePickerField
                v-model="bound.media.value"
                :label="t('backend.posts.grid.zone_image')"
            />
            <!-- Only reached when nothing is picked above, which is the order
                 the renderer uses too. A document carries a focal point, a
                 sized variant and an alt of its own; an address carries none of
                 that, so it stands in rather than competes. -->
            <AppInput
                v-if="!bound.media.value?.id"
                v-model="bound.mediaUrl.value"
                :label="t('backend.posts.grid.zone_image_url')"
                :hint="t('backend.posts.grid.zone_image_url_hint')"
                placeholder="https://…"
            />
            <!-- The one vertical control the grid has, and the only one
                 it will get: a shape to crop to, not a height. Shared
                 rather than per language, like the span — how a picture
                 is cropped is design, written once. -->
            <AppChoiceRow
                v-model="bound.ratio.value"
                :label="t('backend.posts.grid.ratio')"
                :hint="t('backend.posts.grid.ratio_hint')"
                :options="ratioOptions"
            />
            <div class="rounded-lg border border-dashed border-line p-3 space-y-4">
                <p class="text-xs uppercase tracking-wide text-muted">
                    {{ t("backend.posts.grid.translated_fields", { locale }) }}
                </p>
                <AppInput
                    v-model="bound.alt.value"
                    :label="t('backend.posts.grid.zone_alt')"
                />
                <AppInput
                    v-model="bound.caption.value"
                    :label="t('backend.posts.grid.zone_caption')"
                />
            </div>
        </template>

        <template v-else-if="zone.type === 'post'">
            <!-- Shared, not translated: the linked publication carries
                 its own translations and the page picks the right one. -->
            <AppSelect
                v-model="bound.postId.value"
                :label="t('backend.posts.grid.zone_post')"
                :hint="t('backend.posts.grid.zone_post_hint')"
                :options="publicationOptions"
            />
        </template>

        <!-- Named rather than left as a catch-all `v-else`: a stack holds zones,
             not content of its own, and a catch-all would have offered it a
             video address. -->
        <template v-else-if="zone.type === 'video'">
            <div class="rounded-lg border border-dashed border-line p-3 space-y-4">
                <p class="text-xs uppercase tracking-wide text-muted">
                    {{ t("backend.posts.grid.translated_fields", { locale }) }}
                </p>
                <!-- The address is per language: a localised video has
                     a localised URL. -->
                <AppInput
                    v-model="bound.url.value"
                    :label="t('backend.posts.grid.zone_video')"
                    :hint="t('backend.posts.grid.zone_video_hint')"
                    placeholder="https://youtu.be/…"
                />
                <AppInput
                    v-model="bound.caption.value"
                    :label="t('backend.posts.grid.zone_caption')"
                />
            </div>
        </template>
    </div>
</template>
