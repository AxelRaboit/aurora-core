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
 * to the right half - shared on the post, or translated - so nothing here needs
 * to know that split exists.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import AppBlockEditor from "@/shared/components/editor/AppBlockEditor.vue";
import AppChoiceRow from "@/shared/components/form/select/AppChoiceRow.vue";
import AppImagePickerField from "@/shared/components/form/file/AppImagePickerField.vue";
import { openDocumentPicker } from "@/shared/utils/documentPicker.js";
import { MAX_GALLERY_IMAGES } from "../composables/usePostGrid.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import { ChevronDown, ChevronUp, Plus, Trash2 } from "lucide-vue-next";

const props = defineProps({
    /** The zone itself - read for its type, never written to. */
    zone: { type: Object, required: true },
    /** Its writable computeds, from `usePostGrid().zoneFields(...)`. */
    fields: { type: Object, required: true },
    /** Which language the translated fields belong to. */
    locale: { type: String, required: true },
    /** Publications a `post` zone may name. */
    postOptions: { type: Array, default: () => [] },
    /** The types a list zone may narrow to. */
    postTypeOptions: { type: Array, default: () => [] },
    /** The terms it may narrow to, across every taxonomy. */
    termOptions: { type: Array, default: () => [] },
    /** The taxonomies a terms zone may unroll. */
    taxonomyOptions: { type: Array, default: () => [] },
    /** The active forms a zone may pose. */
    formOptions: { type: Array, default: () => [] },
    /** True for a zone inside a stack, where the row controls do not apply. */
    inStack: { type: Boolean, default: false },
    /** The shapes a media zone may be cropped to. */
    ratioOptions: { type: Array, default: () => [] },
    /** How much of its zone's width a picture may take. */
    scaleOptions: { type: Array, default: () => [] },
    /** Which side it sits on once it takes less than all of it. */
    alignOptions: { type: Array, default: () => [] },
    /** The choices a button, a separator and an item list offer. */
    choices: { type: Object, default: () => ({}) },
    /** The entries of an item list - the shared half, in order. */
    items: { type: Array, default: () => [] },
    /** One entry's writable computeds, by position. */
    itemFields: { type: Function, default: () => () => ({}) },
    /** False once the list is at its cap. */
    canAddItem: { type: Boolean, default: true },
});

const emit = defineEmits([
    "add-item",
    "remove-item",
    "move-item",
    "add-gallery",
    "remove-gallery",
    "move-gallery",
    "set-compare",
]);

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
 * zone id - a different zone is a different instance of this component, not the
 * same one handed a new object.
 */
const bound = props.fields;

/** Opens the library filtered to what a browser can play. */
async function pickVideo() {
    const picked = await openDocumentPicker({ mimePrefix: "video/" });

    if (picked) {
        bound.media.value = picked;
    }
}

/** The same, for a recording. */
async function pickAudio() {
    const picked = await openDocumentPicker({ mimePrefix: "audio/" });

    if (picked) {
        bound.media.value = picked;
    }
}

/**
 * Unfiltered: a document zone offers whatever the library holds, which is the
 * point of it - a plaquette is a PDF, a price list is often a spreadsheet, and
 * a press kit is a zip.
 */
async function pickDocument() {
    const picked = await openDocumentPicker({});

    if (picked) {
        bound.media.value = picked;
    }
}

/**
 * The library, filtered to pictures and letting several be taken at once -
 * which is the whole ergonomic point of a gallery zone over six media zones.
 */
async function pickGalleryImages() {
    const picked = await openDocumentPicker({ imagesOnly: true, multiple: true });

    if (Array.isArray(picked) && picked.length > 0) {
        emit("add-gallery", picked);
    }
}

/**
 * The two sides of a comparison, as pickers rather than as a list.
 *
 * They share the gallery's `mediaIds` - position is what says which is which -
 * but an author choosing "before" and "after" should be shown two labelled
 * slots, not two rows of an ordered list they have to reason about.
 */
async function pickCompare(slot) {
    const picked = await openDocumentPicker({ imagesOnly: true });

    if (!picked?.id) return;

    emit("set-compare", slot, picked);
}

const compareImages = computed(() => props.zone.gallery?.items ?? []);

/** What the panel draws: the previews, which travel beside the saved ids. */
const galleryImages = computed(() => props.zone.gallery?.items ?? []);

const galleryIsFull = computed(() => galleryImages.value.length >= MAX_GALLERY_IMAGES);

const publicationOptions = computed(() =>
    props.postOptions.map((post) => ({ value: post.id, label: post.title ?? `#${post.id}` })),
);

/**
 * What each of the four per-entry fields is called, for the costume being
 * worn. The fields are the same four in the database - a step's title is a
 * figure's value is a question - so only their names change here, and
 * switching display keeps what was written.
 *
 * `null` hides a field: a figure has no third line, a logo has no body text.
 */
const ITEM_LABELS = {
    steps: { title: "step_title", description: "step_text", caption: null, url: null },
    stats: { title: "stat_value", description: "stat_label", caption: null, url: null },
    faq: { title: "faq_question", description: "faq_answer", caption: null, url: null },
    quotes: { title: "quote_author", description: "quote_text", caption: "quote_role", url: null },
    logos: { title: "logo_name", description: null, caption: null, url: "logo_url" },
    // The date is the caption, which is the field a quote uses for a role: the
    // small line beside the title. Reusing it rather than adding a fifth is
    // what lets an author switch costume without losing what they wrote.
    timeline: { title: "timeline_title", description: "timeline_text", caption: "timeline_date", url: null },
    offers: { title: "offer_name", description: "offer_lines", caption: "offer_price", url: "offer_url" },
    // The role is the caption, like a quote's: the small line under the name.
    // Same field, so an author who tries the quotes costume and comes back
    // still has the roles they typed.
    people: { title: "person_name", description: "person_bio", caption: "person_role", url: "person_url" },
};

const itemLabels = computed(() => ITEM_LABELS[bound.display.value] ?? ITEM_LABELS.steps);

/** The costumes that hang a picture on an entry: a face, a mark, a portrait. */
const itemHasMedia = computed(() =>
    ["quotes", "logos", "people"].includes(bound.display.value),
);

/** Only the displays that lay their entries in a row have a count to choose. */
const itemHasColumns = computed(() =>
    ["stats", "quotes", "offers", "people"].includes(bound.display.value),
);

/**
 * Only an offer list singles one entry out. The flag is stored on every entry
 * whatever the costume - like the picture - so switching to offers and back
 * does not lose which one was recommended.
 */
const itemHasFeatured = computed(() => "offers" === bound.display.value);

/**
 * The one field that needs a word of explanation: what an author types on
 * several lines becomes several bullets, and nothing on the screen says so.
 */
const descriptionHint = computed(() =>
    "offers" === bound.display.value ? t("backend.posts.grid.offer_lines_hint") : undefined,
);

/**
 * Only the folding costume has panels to keep shut.
 *
 * The flag is stored on every list whatever the costume, like the picture and
 * the recommended flag: trying another costume and coming back should not lose
 * the choice.
 */
const itemFolds = computed(() => "faq" === bound.display.value);

/**
 * What the chosen costume is good for, where the name alone does not say it.
 *
 * `faq` is the one that needs it: it folds any pair of a title and a few
 * lines - what is included, the guarantees, the small print - and an author
 * reading "Questions fréquentes" has no way to know that. The generic line is
 * the fallback, so a costume whose name is its own explanation says nothing
 * extra.
 */
const displayHint = computed(() =>
    itemFolds.value
        ? t("backend.posts.grid.item_display_hint_faq")
        : t("backend.posts.grid.item_display_hint"),
);
</script>

<template>
    <div class="space-y-4">
        <template v-if="zone.type === 'text'">
            <AppChoiceRow
                v-model="bound.textSize.value"
                :label="t('backend.posts.grid.text_size')"
                :hint="t('backend.posts.grid.text_size_hint')"
                :options="choices.textSize ?? []"
            />
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
                 rather than per language, like the span - how a picture
                 is cropped is design, written once. -->
            <AppChoiceRow
                v-model="bound.ratio.value"
                :label="t('backend.posts.grid.ratio')"
                :hint="t('backend.posts.grid.ratio_hint')"
                :options="ratioOptions"
            />
            <!-- Not a second way to say the same thing as the width above: that
                 one moves the zone's neighbours, this one leaves the zone where
                 it is and prints the picture smaller inside it. -->
            <AppChoiceRow
                v-model="bound.scale.value"
                :label="t('backend.posts.grid.scale')"
                :hint="t('backend.posts.grid.scale_hint')"
                :options="scaleOptions"
            />
            <!-- Only once the picture is narrower than its zone, which is the
                 only time the question exists: one filling its zone has no side
                 to be on, and offering the choice there would be offering three
                 buttons that all do nothing. -->
            <AppChoiceRow
                v-if="bound.scale.value !== 100"
                v-model="bound.align.value"
                :label="t('backend.posts.grid.align')"
                :options="alignOptions"
            />
            <div class="rounded-lg border border-dashed border-line p-3 space-y-4">
                <p class="text-xs uppercase tracking-wide text-muted">
                    {{ t("backend.posts.grid.translated_fields", { locale }) }}
                </p>
                <AppInput
                    v-model="bound.alt.value"
                    :label="t('backend.posts.grid.zone_alt')"
                    :placeholder="t('backend.posts.alt_placeholder')"
                />
                <AppInput
                    v-model="bound.caption.value"
                    :label="t('backend.posts.grid.zone_caption')"
                    :placeholder="t('backend.posts.caption_placeholder')"
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
            <AppChoiceRow
                v-model="bound.cardVariant.value"
                :label="t('backend.posts.grid.card_variant')"
                :options="choices.cardVariant ?? []"
            />
        </template>

        <template v-else-if="zone.type === 'code'">
            <AppSelect
                v-model="bound.language.value"
                :label="t('backend.posts.grid.code_language')"
                :hint="t('backend.posts.grid.code_language_hint')"
                :options="choices.language ?? []"
                :placeholder="t('backend.posts.grid.code_language_none')"
            />
            <AppToggle
                v-model="bound.lineNumbers.value"
                :label="t('backend.posts.grid.code_line_numbers')"
                :hint="t('backend.posts.grid.code_line_numbers_hint')"
            />
            <div class="rounded-lg border border-dashed border-line p-3 space-y-2">
                <p class="text-xs uppercase tracking-wide text-muted">
                    {{ t("backend.posts.grid.translated_fields", { locale }) }}
                </p>
                <!-- Per language like any other text: a snippet often carries
                     comments, and a comment is written for a reader. -->
                <AppTextarea
                    v-model="bound.code.value"
                    :label="t('backend.posts.grid.code')"
                    :placeholder="t('backend.posts.grid.code_placeholder')"
                    :rows="8"
                    class="font-mono"
                />
            </div>
        </template>

        <template v-else-if="zone.type === 'form'">
            <!-- Shared, not translated: a form carries its own translations
                 and the page picks the right one, exactly as a linked
                 publication does. -->
            <AppSelect
                v-model="bound.formId.value"
                :label="t('backend.posts.grid.zone_form')"
                :hint="t('backend.posts.grid.zone_form_hint')"
                :options="formOptions"
                :placeholder="t('backend.posts.grid.zone_form_none')"
            />
        </template>

        <template v-else-if="zone.type === 'compare'">
            <!-- Both or neither: the zone draws nothing until the pair is
                 complete, and the panel says so rather than leaving an author
                 to discover it on the published page. -->
            <div class="grid grid-cols-2 gap-3">
                <div v-for="(slot, slotIndex) in [0, 1]" :key="slot" class="space-y-2">
                    <p class="text-xs uppercase tracking-wide text-muted">
                        {{ slotIndex === 0
                            ? t("backend.posts.grid.compare_before")
                            : t("backend.posts.grid.compare_after") }}
                    </p>
                    <button
                        type="button"
                        class="block w-full overflow-hidden rounded-lg border border-dashed border-line"
                        v-on:click="pickCompare(slotIndex)"
                    >
                        <img
                            v-if="compareImages[slotIndex]?.url"
                            :src="compareImages[slotIndex].url"
                            alt=""
                            class="aspect-square w-full object-cover"
                        >
                        <span v-else class="flex aspect-square items-center justify-center text-sm text-muted">
                            {{ t("backend.posts.grid.compare_pick") }}
                        </span>
                    </button>
                </div>
            </div>

            <div class="rounded-lg border border-dashed border-line p-3 space-y-4">
                <p class="text-xs uppercase tracking-wide text-muted">
                    {{ t("backend.posts.grid.translated_fields", { locale }) }}
                </p>
                <!-- The words under each side. Empty is the common case, and
                     the page says "Avant" and "Après" on its own. -->
                <AppInput
                    v-model="bound.alt.value"
                    :label="t('backend.posts.grid.compare_before_label')"
                    :placeholder="t('backend.posts.grid.compare_before')"
                />
                <AppInput
                    v-model="bound.label.value"
                    :label="t('backend.posts.grid.compare_after_label')"
                    :placeholder="t('backend.posts.grid.compare_after')"
                />
                <AppInput
                    v-model="bound.caption.value"
                    :label="t('backend.posts.grid.zone_caption')"
                    :placeholder="t('backend.posts.caption_placeholder')"
                />
            </div>
        </template>

        <template v-else-if="zone.type === 'gallery'">
            <div class="flex items-center justify-between gap-3">
                <span class="text-sm text-secondary">
                    {{ t("backend.posts.grid.gallery_count", { count: galleryImages.length, max: MAX_GALLERY_IMAGES }) }}
                </span>
                <AppButton
                    variant="secondary"
                    size="sm"
                    :disabled="galleryIsFull"
                    v-on:click="pickGalleryImages"
                >
                    <Plus class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ t("backend.posts.grid.gallery_add") }}
                </AppButton>
            </div>

            <!-- Thumbnails rather than a list of names: an author arranging
                 photographs is looking at photographs. Up and down rather than
                 dragging, which is what the zones themselves offer and what
                 stays reachable from a keyboard. -->
            <ul v-if="galleryImages.length" class="m-0 grid list-none grid-cols-3 gap-2 p-0">
                <li
                    v-for="(picture, pictureIndex) in galleryImages"
                    :key="`${pictureIndex}-${picture.url}`"
                    class="group relative overflow-hidden rounded-lg border border-line"
                >
                    <img :src="picture.url" alt="" class="aspect-square w-full object-cover">
                    <div class="absolute inset-x-0 bottom-0 flex justify-between gap-1 bg-surface/90 p-1">
                        <div class="flex gap-1">
                            <AppIconButton
                                :icon="ChevronUp"
                                size="sm"
                                :disabled="pictureIndex === 0"
                                :title="t('backend.posts.grid.item_move_up')"
                                v-on:click="emit('move-gallery', pictureIndex, -1)"
                            />
                            <AppIconButton
                                :icon="ChevronDown"
                                size="sm"
                                :disabled="pictureIndex === galleryImages.length - 1"
                                :title="t('backend.posts.grid.item_move_down')"
                                v-on:click="emit('move-gallery', pictureIndex, 1)"
                            />
                        </div>
                        <AppIconButton
                            :icon="Trash2"
                            size="sm"
                            color="danger"
                            :title="t('backend.posts.grid.gallery_remove')"
                            v-on:click="emit('remove-gallery', pictureIndex)"
                        />
                    </div>
                </li>
            </ul>
            <p v-else class="text-sm text-muted">{{ t("backend.posts.grid.gallery_empty") }}</p>

            <AppChoiceRow
                v-model="bound.columns.value"
                :label="t('backend.posts.grid.item_columns')"
                :options="choices.columns ?? []"
            />
            <!-- The same control the media zone offers, and the same words.
                 Asked for their own proportions the pictures flow down
                 columns; asked for a shape they are cropped into a grid. -->
            <AppChoiceRow
                v-model="bound.ratio.value"
                :label="t('backend.posts.grid.ratio')"
                :hint="t('backend.posts.grid.gallery_ratio_hint')"
                :options="ratioOptions"
            />

            <div class="rounded-lg border border-dashed border-line p-3 space-y-4">
                <p class="text-xs uppercase tracking-wide text-muted">
                    {{ t("backend.posts.grid.translated_fields", { locale }) }}
                </p>
                <AppInput
                    v-model="bound.caption.value"
                    :label="t('backend.posts.grid.zone_caption')"
                    :placeholder="t('backend.posts.caption_placeholder')"
                />
            </div>
        </template>

        <template v-else-if="zone.type === 'map'">
            <!-- The author's own picture, and shared like every other: a shop
                 front is the same shop front in every language. -->
            <AppImagePickerField
                v-model="bound.media.value"
                :label="t('backend.posts.grid.map_image')"
                :hint="t('backend.posts.grid.map_image_hint')"
            />
            <div class="rounded-lg border border-dashed border-line p-3 space-y-4">
                <p class="text-xs uppercase tracking-wide text-muted">
                    {{ t("backend.posts.grid.translated_fields", { locale }) }}
                </p>
                <AppInput
                    v-model="bound.label.value"
                    :label="t('backend.posts.grid.map_name')"
                    :placeholder="t('backend.posts.grid.map_name_placeholder')"
                />
                <!-- Translated because a country is not spelled the same in
                     every language, and because the floor and the door code
                     are words rather than coordinates. -->
                <AppTextarea
                    v-model="bound.caption.value"
                    :label="t('backend.posts.grid.map_address')"
                    :hint="t('backend.posts.grid.map_address_hint')"
                    :placeholder="t('backend.posts.grid.map_address_placeholder')"
                    :rows="4"
                />
            </div>
        </template>

        <template v-else-if="zone.type === 'terms'">
            <AppSelect
                v-model="bound.taxonomyId.value"
                :label="t('backend.posts.grid.terms_taxonomy')"
                :hint="t('backend.posts.grid.terms_taxonomy_hint')"
                :options="taxonomyOptions"
                :placeholder="t('backend.posts.grid.terms_taxonomy_none')"
            />
            <div class="rounded-lg border border-dashed border-line p-3 space-y-4">
                <p class="text-xs uppercase tracking-wide text-muted">
                    {{ t("backend.posts.grid.translated_fields", { locale }) }}
                </p>
                <!-- The words above the chips. The taxonomy has a name of its
                     own, but it is the one the backend files things under, not
                     necessarily the one a visitor should read. -->
                <AppInput
                    v-model="bound.caption.value"
                    :label="t('backend.posts.grid.terms_caption')"
                    :placeholder="t('backend.posts.grid.terms_caption_placeholder')"
                />
            </div>
        </template>

        <template v-else-if="zone.type === 'postList'">
            <!-- Both filters are optional and combine. Left alone, the zone
                 shows the newest publications of the whole site, which is the
                 answer that needs no setting up. -->
            <AppSelect
                v-model="bound.postTypeId.value"
                :label="t('backend.posts.grid.list_post_type')"
                :hint="t('backend.posts.grid.list_post_type_hint')"
                :options="postTypeOptions"
                :placeholder="t('backend.posts.grid.list_any')"
            />
            <AppSelect
                v-model="bound.termId.value"
                :label="t('backend.posts.grid.list_term')"
                :hint="t('backend.posts.grid.list_term_hint')"
                :options="termOptions"
                :placeholder="t('backend.posts.grid.list_any')"
            />
            <AppChoiceRow
                v-model="bound.limit.value"
                :label="t('backend.posts.grid.list_limit')"
                :options="choices.limit ?? []"
            />
            <AppChoiceRow
                v-model="bound.cardVariant.value"
                :label="t('backend.posts.grid.card_variant')"
                :options="choices.cardVariant ?? []"
            />
            <!-- A compact list is a dense column by design, so the count of
                 columns is not a question it has. -->
            <AppChoiceRow
                v-if="bound.cardVariant.value !== 'compact'"
                v-model="bound.columns.value"
                :label="t('backend.posts.grid.item_columns')"
                :options="choices.columns ?? []"
            />
        </template>

        <template v-else-if="zone.type === 'button'">
            <AppChoiceRow
                v-model="bound.variant.value"
                :label="t('backend.posts.grid.button_variant')"
                :options="choices.variant ?? []"
            />
            <AppChoiceRow
                v-model="bound.size.value"
                :label="t('backend.posts.grid.size')"
                :options="choices.size ?? []"
            />
            <AppChoiceRow
                v-model="bound.align.value"
                :label="t('backend.posts.grid.align')"
                :options="alignOptions"
            />
            <div class="rounded-lg border border-dashed border-line p-3 space-y-4">
                <p class="text-xs uppercase tracking-wide text-muted">
                    {{ t("backend.posts.grid.translated_fields", { locale }) }}
                </p>
                <!-- Both halves are translated: a localised page has a
                     localised address as much as a localised word on it. -->
                <AppInput
                    v-model="bound.label.value"
                    :label="t('backend.posts.grid.button_label')"
                    :placeholder="t('backend.posts.grid.button_label_placeholder')"
                />
                <AppInput
                    v-model="bound.url.value"
                    :label="t('backend.posts.grid.button_url')"
                    placeholder="https://…"
                />
            </div>
        </template>

        <template v-else-if="zone.type === 'separator'">
            <AppChoiceRow
                v-model="bound.separatorStyle.value"
                :label="t('backend.posts.grid.separator_style')"
                :hint="t('backend.posts.grid.separator_style_hint')"
                :options="choices.separatorStyle ?? []"
            />
            <AppChoiceRow
                v-model="bound.size.value"
                :label="t('backend.posts.grid.size')"
                :options="choices.size ?? []"
            />
        </template>

        <template v-else-if="zone.type === 'items'">
            <AppChoiceRow
                v-model="bound.display.value"
                :label="t('backend.posts.grid.item_display')"
                :hint="displayHint"
                :options="choices.display ?? []"
            />
            <!-- Only where there are panels to keep shut. -->
            <AppToggle
                v-if="itemFolds"
                v-model="bound.exclusiveOpen.value"
                :label="t('backend.posts.grid.item_exclusive_open')"
                :hint="t('backend.posts.grid.item_exclusive_open_hint')"
            />
            <!-- Only where the costume lays its entries in a row: a list of
                 steps and an accordion read down the page, and offering a
                 column count there would offer a control that does nothing. -->
            <AppChoiceRow
                v-if="itemHasColumns"
                v-model="bound.columns.value"
                :label="t('backend.posts.grid.item_columns')"
                :options="choices.columns ?? []"
            />

            <div class="space-y-3">
                <div
                    v-for="(item, itemIndex) in items"
                    :key="item.id"
                    class="rounded-lg border border-line p-3 space-y-3"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs uppercase tracking-wide text-muted">
                            {{ t("backend.posts.grid.item_number", { number: itemIndex + 1 }) }}
                        </span>
                        <div class="flex items-center gap-1">
                            <AppIconButton
                                :icon="ChevronUp"
                                size="sm"
                                :title="t('backend.posts.grid.item_move_up')"
                                :disabled="itemIndex === 0"
                                v-on:click="emit('move-item', itemIndex, -1)"
                            />
                            <AppIconButton
                                :icon="ChevronDown"
                                size="sm"
                                :title="t('backend.posts.grid.item_move_down')"
                                :disabled="itemIndex === items.length - 1"
                                v-on:click="emit('move-item', itemIndex, 1)"
                            />
                            <AppIconButton
                                :icon="Trash2"
                                size="sm"
                                variant="danger"
                                :title="t('backend.posts.grid.item_remove')"
                                v-on:click="emit('remove-item', itemIndex)"
                            />
                        </div>
                    </div>

                    <AppImagePickerField
                        v-if="itemHasMedia"
                        v-model="itemFields(itemIndex).media.value"
                        :label="t('backend.posts.grid.item_image')"
                    />

                    <div class="rounded-lg border border-dashed border-line p-3 space-y-3">
                        <p class="text-xs uppercase tracking-wide text-muted">
                            {{ t("backend.posts.grid.translated_fields", { locale }) }}
                        </p>
                        <AppInput
                            v-if="itemLabels.title"
                            v-model="itemFields(itemIndex).title.value"
                            :label="t(`backend.posts.grid.${itemLabels.title}`)"
                            :placeholder="t(`backend.posts.grid.${itemLabels.title}_placeholder`)"
                        />
                        <AppTextarea
                            v-if="itemLabels.description"
                            v-model="itemFields(itemIndex).description.value"
                            :label="t(`backend.posts.grid.${itemLabels.description}`)"
                            :placeholder="t(`backend.posts.grid.${itemLabels.description}_placeholder`)"
                            :hint="descriptionHint"
                            :rows="itemLabels.description === 'offer_lines' ? 4 : 2"
                        />
                        <AppInput
                            v-if="itemLabels.caption"
                            v-model="itemFields(itemIndex).caption.value"
                            :label="t(`backend.posts.grid.${itemLabels.caption}`)"
                            :placeholder="t(`backend.posts.grid.${itemLabels.caption}_placeholder`)"
                        />
                        <AppInput
                            v-if="itemLabels.url"
                            v-model="itemFields(itemIndex).url.value"
                            :label="t(`backend.posts.grid.${itemLabels.url}`)"
                            :placeholder="t(`backend.posts.grid.${itemLabels.url}_placeholder`)"
                        />
                    </div>

                    <!-- Outside the translated block, deliberately: which plan
                         is recommended is the same recommendation in every
                         language, like the picture above. -->
                    <AppToggle
                        v-if="itemHasFeatured"
                        v-model="itemFields(itemIndex).featured.value"
                        :label="t('backend.posts.grid.offer_featured')"
                        :hint="t('backend.posts.grid.offer_featured_hint')"
                    />
                </div>

                <AppButton
                    variant="secondary"
                    size="sm"
                    :disabled="!canAddItem"
                    v-on:click="emit('add-item')"
                >
                    <Plus class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ t("backend.posts.grid.item_add") }}
                </AppButton>
            </div>
        </template>

        <!-- Named rather than left as a catch-all `v-else`: a stack holds zones,
             not content of its own, and a catch-all would have offered it a
             video address. -->
        <template v-else-if="zone.type === 'video'">
            <!-- A film the site hosts, chosen like a picture. Shared by every
                 language: the same file plays whatever the page is read in,
                 which is why it sits outside the translated block below. -->
            <div class="flex items-center gap-3">
                <span class="min-w-0 flex-1 truncate text-sm text-secondary">
                    {{ bound.media.value?.id
                        ? t("backend.posts.grid.zone_video_file_chosen")
                        : t("backend.posts.grid.zone_video_file_none") }}
                </span>
                <AppTextLinkButton size="xs" v-on:click="pickVideo">
                    {{ bound.media.value?.id ? t("shared.media.change") : t("backend.posts.grid.zone_video_file") }}
                </AppTextLinkButton>
                <AppTextLinkButton
                    v-if="bound.media.value?.id"
                    color="danger"
                    size="xs"
                    v-on:click="bound.media.value = null"
                >
                    {{ t("shared.common.remove") }}
                </AppTextLinkButton>
            </div>

            <div class="rounded-lg border border-dashed border-line p-3 space-y-4">
                <p class="text-xs uppercase tracking-wide text-muted">
                    {{ t("backend.posts.grid.translated_fields", { locale }) }}
                </p>
                <!-- Only reached when no file is picked above, which is the
                     order the renderer uses too: a hosted film is played by
                     the browser, an address by its provider. -->
                <AppInput
                    v-if="!bound.media.value?.id"
                    v-model="bound.url.value"
                    :label="t('backend.posts.grid.zone_video')"
                    :hint="t('backend.posts.grid.zone_video_hint')"
                    placeholder="https://youtu.be/…"
                />
                <AppInput
                    v-model="bound.caption.value"
                    :label="t('backend.posts.grid.zone_caption')"
                    :placeholder="t('backend.posts.caption_placeholder')"
                />
            </div>
        </template>

        <template v-else-if="zone.type === 'audio'">
            <!-- Shared by every language, like the film above: the same
                 recording plays whatever the page is read in. -->
            <div class="flex items-center gap-3">
                <span class="min-w-0 flex-1 truncate text-sm text-secondary">
                    {{ bound.media.value?.id
                        ? t("backend.posts.grid.zone_audio_file_chosen")
                        : t("backend.posts.grid.zone_audio_file_none") }}
                </span>
                <AppTextLinkButton size="xs" v-on:click="pickAudio">
                    {{ bound.media.value?.id ? t("shared.media.change") : t("backend.posts.grid.zone_audio_file") }}
                </AppTextLinkButton>
                <AppTextLinkButton
                    v-if="bound.media.value?.id"
                    color="danger"
                    size="xs"
                    v-on:click="bound.media.value = null"
                >
                    {{ t("shared.common.remove") }}
                </AppTextLinkButton>
            </div>

            <div class="rounded-lg border border-dashed border-line p-3 space-y-4">
                <p class="text-xs uppercase tracking-wide text-muted">
                    {{ t("backend.posts.grid.translated_fields", { locale }) }}
                </p>
                <AppInput
                    v-model="bound.caption.value"
                    :label="t('backend.posts.grid.zone_caption')"
                    :placeholder="t('backend.posts.caption_placeholder')"
                />
            </div>
        </template>

        <template v-else-if="zone.type === 'document'">
            <div class="flex items-center gap-3">
                <span class="min-w-0 flex-1 truncate text-sm text-secondary">
                    {{ bound.media.value?.id
                        ? t("backend.posts.grid.zone_document_chosen")
                        : t("backend.posts.grid.zone_document_none") }}
                </span>
                <AppTextLinkButton size="xs" v-on:click="pickDocument">
                    {{ bound.media.value?.id ? t("shared.media.change") : t("backend.posts.grid.zone_document_file") }}
                </AppTextLinkButton>
                <AppTextLinkButton
                    v-if="bound.media.value?.id"
                    color="danger"
                    size="xs"
                    v-on:click="bound.media.value = null"
                >
                    {{ t("shared.common.remove") }}
                </AppTextLinkButton>
            </div>

            <!-- Said here rather than discovered on the published page: the
                 library is where a client's internal papers live too, and a
                 zone that silently renders nothing looks like a bug. -->
            <p class="text-xs text-muted">
                {{ t("backend.posts.grid.zone_document_published_hint") }}
            </p>

            <div class="rounded-lg border border-dashed border-line p-3 space-y-4">
                <p class="text-xs uppercase tracking-wide text-muted">
                    {{ t("backend.posts.grid.translated_fields", { locale }) }}
                </p>
                <AppInput
                    v-model="bound.label.value"
                    :label="t('backend.posts.grid.zone_document_label')"
                    :hint="t('backend.posts.grid.zone_document_label_hint')"
                    :placeholder="t('backend.posts.grid.zone_document_label_placeholder')"
                />
                <AppInput
                    v-model="bound.caption.value"
                    :label="t('backend.posts.grid.zone_caption')"
                    :placeholder="t('backend.posts.caption_placeholder')"
                />
            </div>
        </template>
    </div>
</template>
