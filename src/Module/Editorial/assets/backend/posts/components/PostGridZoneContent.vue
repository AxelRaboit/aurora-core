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
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import AppBlockEditor from "@/shared/components/editor/AppBlockEditor.vue";
import AppChoiceRow from "@/shared/components/form/select/AppChoiceRow.vue";
import AppImagePickerField from "@/shared/components/form/file/AppImagePickerField.vue";
import { openDocumentPicker } from "@/shared/utils/documentPicker.js";
import { MAX_GALLERY_IMAGES } from "../composables/usePostGrid.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppDatePicker from "@/shared/components/form/picker/AppDatePicker.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import { ChevronDown, ChevronUp, Plus, Trash2 } from "lucide-vue-next";
import OpeningHoursField from "./zones/OpeningHoursField.vue";
import { parseLines } from "./zones/openingHours.js";

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
    /** The presentations a deck zone may show. */
    deckOptions: { type: Array, default: () => [] },
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

/**
 * The face beside a social post: an id under `options`, like every setting of
 * one kind of zone. The picker wants `{id, url}`; the url is kept only for the
 * preview until the next save, the page resolving it again from the id.
 */
const avatarPreview = ref(null);
const logoPreview = ref(null);

/** The picture in the middle of a QR code, the same way. */
const qrLogo = computed({
    get: () => ({ id: bound.qrLogoId.value ?? null, url: logoPreview.value }),
    set: (picked) => {
        bound.qrLogoId.value = picked?.id ?? null;
        logoPreview.value = picked?.url ?? null;
    },
});
const socialAvatar = computed({
    get: () => ({ id: bound.socialAvatarId.value ?? null, url: avatarPreview.value }),
    set: (picked) => {
        bound.socialAvatarId.value = picked?.id ?? null;
        avatarPreview.value = picked?.url ?? null;
    },
});

/** Opens the library filtered to what a browser can play. */
async function pickVideo() {
    const picked = await openDocumentPicker({ mimePrefix: "video/" });

    if (picked) {
        bound.media.value = picked;
    }
}

/** Several films at once, for a wall: the gallery's controls do the rest. */
async function pickWallVideos() {
    const picked = await openDocumentPicker({ mimePrefix: "video/", multiple: true });

    if (Array.isArray(picked) && picked.length > 0) {
        emit("add-gallery", picked);
    } else if (picked?.id) {
        emit("add-gallery", [picked]);
    }
}

/** Mirrors GridNormalizer::MAX_VIDEO_WALL. */
const MAX_VIDEO_WALL = 12;

/** Mirrors GridNormalizer::MAX_TRAVEL_STOPS. */
const MAX_TRAVEL_STOPS = 20;

async function pickTravelPhotos() {
    const picked = await openDocumentPicker({ imagesOnly: true, multiple: true });

    if (Array.isArray(picked) && picked.length > 0) {
        emit("add-gallery", picked);
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
            <!-- A screen drawn around the picture, for a site shown on the
                 device it was built for. The frame sets the shape, so the
                 ratio below is set aside while one is chosen. -->
            <AppChoiceRow
                v-model="bound.frame.value"
                :label="t('backend.posts.grid.frame')"
                :hint="t('backend.posts.grid.frame_hint')"
                :options="choices.frame ?? []"
            />
            <AppToggle
                v-model="bound.parallax.value"
                :label="t('backend.posts.grid.parallax')"
                :hint="t('backend.posts.grid.parallax_hint')"
            />
            <AppToggle
                v-model="bound.showExif.value"
                :label="t('backend.posts.grid.show_exif')"
                :hint="t('backend.posts.grid.show_exif_hint')"
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

        <template v-else-if="zone.type === 'embed'">
            <p class="text-xs text-muted">{{ t("backend.posts.grid.embed_providers") }}</p>
            <div class="rounded-lg border border-dashed border-line p-3 space-y-4">
                <p class="text-xs uppercase tracking-wide text-muted">
                    {{ t("backend.posts.grid.translated_fields", { locale }) }}
                </p>
                <!-- Translated, like a video address: a booking page and a
                     podcast episode both have a language. -->
                <AppInput
                    v-model="bound.url.value"
                    :label="t('backend.posts.grid.zone_embed')"
                    :hint="t('backend.posts.grid.zone_embed_hint')"
                    placeholder="https://open.spotify.com/episode/…"
                />
                <AppInput
                    v-model="bound.caption.value"
                    :label="t('backend.posts.grid.zone_caption')"
                    :placeholder="t('backend.posts.caption_placeholder')"
                />
            </div>
        </template>

        <template v-else-if="zone.type === 'tabs'">
            <p class="text-xs text-muted">{{ t("backend.posts.grid.tabs_hint") }}</p>

            <div class="space-y-3">
                <div
                    v-for="(panel, panelIndex) in items"
                    :key="panel.id"
                    class="rounded-lg border border-line p-3 space-y-3"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs uppercase tracking-wide text-muted">
                            {{ t("backend.posts.grid.tabs_number", { number: panelIndex + 1 }) }}
                        </span>
                        <div class="flex items-center gap-1">
                            <AppIconButton
                                :icon="ChevronUp"
                                size="sm"
                                :title="t('backend.posts.grid.item_move_up')"
                                :disabled="panelIndex === 0"
                                v-on:click="emit('move-item', panelIndex, -1)"
                            />
                            <AppIconButton
                                :icon="ChevronDown"
                                size="sm"
                                :title="t('backend.posts.grid.item_move_down')"
                                :disabled="panelIndex === items.length - 1"
                                v-on:click="emit('move-item', panelIndex, 1)"
                            />
                            <AppIconButton
                                :icon="Trash2"
                                size="sm"
                                color="danger"
                                :title="t('backend.posts.grid.tabs_remove')"
                                v-on:click="emit('remove-item', panelIndex)"
                            />
                        </div>
                    </div>

                    <div class="rounded-lg border border-dashed border-line p-3 space-y-3">
                        <p class="text-xs uppercase tracking-wide text-muted">
                            {{ t("backend.posts.grid.translated_fields", { locale }) }}
                        </p>
                        <AppInput
                            v-model="itemFields(panelIndex).title.value"
                            :label="t('backend.posts.grid.tabs_label')"
                            :placeholder="t('backend.posts.grid.tabs_label_placeholder')"
                        />
                        <AppBlockEditor
                            v-model="itemFields(panelIndex).blocks.value"
                            :placeholder="t('backend.posts.content_placeholder')"
                        />
                    </div>
                </div>

                <AppButton
                    variant="secondary"
                    size="sm"
                    :disabled="!canAddItem"
                    v-on:click="emit('add-item')"
                >
                    <Plus class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ t("backend.posts.grid.tabs_add") }}
                </AppButton>
            </div>
        </template>

        <template v-else-if="zone.type === 'search'">
            <!-- Left alone, the field searches the whole site, which is the
                 answer that needs no setting up. -->
            <AppSelect
                v-model="bound.postTypeId.value"
                :label="t('backend.posts.grid.search_post_type')"
                :hint="t('backend.posts.grid.search_post_type_hint')"
                :options="postTypeOptions"
                :placeholder="t('backend.posts.grid.list_any')"
            />
        </template>

        <template v-else-if="zone.type === 'comments'">
            <!-- Nothing to choose: a thread belongs to the page it is drawn
                 on, and a choice here would be a way to put one page's replies
                 under another. -->
            <p class="text-sm text-muted">{{ t("backend.posts.grid.comments_hint") }}</p>
        </template>

        <template v-else-if="zone.type === 'githubActivity'">
            <!-- The accounts are the site's, set once in the settings. What a
                 zone chooses is what it shows: their grid, or a few
                 repositories and their releases. -->
            <p class="text-sm text-muted">{{ t("backend.posts.grid.github_activity_hint") }}</p>
            <AppChoiceRow
                v-model="bound.githubMode.value"
                :label="t('backend.posts.grid.github_mode')"
                :options="choices.githubMode ?? []"
            />
            <AppTextarea
                v-if="bound.githubMode.value !== 'activity'"
                :model-value="(bound.githubRepos.value ?? []).join('\n')"
                :label="t('backend.posts.grid.github_repos')"
                :hint="t('backend.posts.grid.github_repos_hint')"
                placeholder="AxelRaboit/aurora-core"
                :rows="3"
                v-on:update:model-value="(value) => (bound.githubRepos.value = parseLines(value))"
            />
        </template>

        <template v-else-if="zone.type === 'availability'">
            <AppChoiceRow
                v-model="bound.availability.value"
                :label="t('backend.posts.grid.availability')"
                :options="choices.availability ?? []"
            />
            <AppDatePicker
                :model-value="bound.availableFrom.value ?? ''"
                :label="t('backend.posts.grid.available_from')"
                :hint="t('backend.posts.grid.available_from_hint')"
                v-on:update:model-value="(value) => (bound.availableFrom.value = value || null)"
            />
            <div class="rounded-lg border border-dashed border-line p-3 space-y-2">
                <p class="text-xs uppercase tracking-wide text-muted">
                    {{ t("backend.posts.grid.translated_fields", { locale }) }}
                </p>
                <AppInput
                    v-model="bound.label.value"
                    placeholder="Disponible pour de nouveaux projets"
                    :label="t('backend.posts.grid.availability_label')"
                    :hint="t('backend.posts.grid.availability_label_hint')"
                />
                <AppInput
                    v-model="bound.caption.value"
                    placeholder="Réponse sous 48 h"
                    :label="t('backend.posts.grid.availability_note')"
                />
            </div>
        </template>

        <template v-else-if="zone.type === 'openingHours'">
            <OpeningHoursField v-model="bound.hours.value" />
            <AppTextarea
                :model-value="(bound.closedDates.value ?? []).join('\n')"
                :label="t('backend.posts.grid.closed_dates')"
                :hint="t('backend.posts.grid.closed_dates_hint')"
                placeholder="2026-12-25"
                :rows="3"
                v-on:update:model-value="(value) => (bound.closedDates.value = parseLines(value))"
            />
            <AppInput
                v-model="bound.timezone.value"
                :label="t('backend.posts.grid.timezone')"
                :hint="t('backend.posts.grid.timezone_hint')"
                placeholder="Europe/Paris"
            />
            <div class="rounded-lg border border-dashed border-line p-3 space-y-2">
                <p class="text-xs uppercase tracking-wide text-muted">
                    {{ t("backend.posts.grid.translated_fields", { locale }) }}
                </p>
                <AppInput
                    v-model="bound.caption.value"
                    placeholder="Fermé les jours fériés"
                    :label="t('backend.posts.grid.hours_note')"
                />
            </div>
        </template>

        <template v-else-if="zone.type === 'countdown'">
            <AppDatePicker
                :model-value="bound.countdownAt.value ?? ''"
                :enable-time="true"
                :label="t('backend.posts.grid.countdown_at')"
                v-on:update:model-value="(value) => (bound.countdownAt.value = value || null)"
            />
            <AppInput
                v-model="bound.timezone.value"
                :label="t('backend.posts.grid.timezone')"
                :hint="t('backend.posts.grid.timezone_hint')"
                placeholder="Europe/Paris"
            />
            <div class="rounded-lg border border-dashed border-line p-3 space-y-2">
                <p class="text-xs uppercase tracking-wide text-muted">
                    {{ t("backend.posts.grid.translated_fields", { locale }) }}
                </p>
                <AppInput
                    v-model="bound.label.value"
                    placeholder="Ouverture de la boutique"
                    :label="t('backend.posts.grid.countdown_title')"
                />
                <AppInput
                    v-model="bound.caption.value"
                    placeholder="La boutique est ouverte"
                    :label="t('backend.posts.grid.countdown_after')"
                    :hint="t('backend.posts.grid.countdown_after_hint')"
                />
            </div>
        </template>

        <template v-else-if="zone.type === 'contactCard'">
            <AppImagePickerField v-model="bound.media.value" :label="t('backend.posts.grid.contact_photo')" />
            <AppInput
                v-model="bound.contactName.value"
                placeholder="Axel Raboit"
                :label="t('backend.posts.grid.contact_name')"
            />
            <AppInput
                v-model="bound.contactPhone.value"
                placeholder="+33 6 12 34 56 78"
                type="tel"
                :label="t('backend.posts.grid.contact_phone')"
            />
            <AppInput
                v-model="bound.contactEmail.value"
                placeholder="contact@exemple.fr"
                type="email"
                :label="t('backend.posts.grid.contact_email')"
            />
            <AppInput
                :model-value="bound.contactWebsite.value ?? ''"
                :label="t('backend.posts.grid.contact_website')"
                placeholder="https://…"
                v-on:update:model-value="(value) => (bound.contactWebsite.value = value || null)"
            />
            <div class="rounded-lg border border-dashed border-line p-3 space-y-2">
                <p class="text-xs uppercase tracking-wide text-muted">
                    {{ t("backend.posts.grid.translated_fields", { locale }) }}
                </p>
                <AppInput
                    v-model="bound.caption.value"
                    placeholder="Photographe"
                    :label="t('backend.posts.grid.contact_role')"
                />
            </div>
        </template>

        <template v-else-if="zone.type === 'socialPost'">
            <AppChoiceRow
                v-model="bound.socialNetwork.value"
                :label="t('backend.posts.grid.social_network')"
                :options="choices.socialNetwork ?? []"
            />
            <AppInput
                v-model="bound.socialName.value"
                placeholder="Axel Raboit"
                :label="t('backend.posts.grid.social_name')"
            />
            <AppInput v-model="bound.socialHandle.value" :label="t('backend.posts.grid.social_handle')" placeholder="@" />
            <AppImagePickerField v-model="socialAvatar" :label="t('backend.posts.grid.social_avatar')" />
            <AppImagePickerField v-model="bound.media.value" :label="t('backend.posts.grid.social_picture')" />
            <div class="grid grid-cols-3 gap-2">
                <AppInput
                    placeholder="1284"
                    :model-value="String(bound.socialLikes.value ?? 0)"
                    type="number"
                    :label="t('backend.posts.grid.social_likes')"
                    v-on:update:model-value="(value) => (bound.socialLikes.value = Math.max(0, Number.parseInt(value, 10) || 0))"
                />
                <AppInput
                    placeholder="46"
                    :model-value="String(bound.socialComments.value ?? 0)"
                    type="number"
                    :label="t('backend.posts.grid.social_comments')"
                    v-on:update:model-value="(value) => (bound.socialComments.value = Math.max(0, Number.parseInt(value, 10) || 0))"
                />
                <AppInput
                    placeholder="12"
                    :model-value="String(bound.socialShares.value ?? 0)"
                    type="number"
                    :label="t('backend.posts.grid.social_shares')"
                    v-on:update:model-value="(value) => (bound.socialShares.value = Math.max(0, Number.parseInt(value, 10) || 0))"
                />
            </div>
            <AppDatePicker
                :model-value="bound.socialDate.value ?? ''"
                :label="t('backend.posts.grid.social_date')"
                v-on:update:model-value="(value) => (bound.socialDate.value = value || null)"
            />
            <div class="rounded-lg border border-dashed border-line p-3 space-y-2">
                <p class="text-xs uppercase tracking-wide text-muted">
                    {{ t("backend.posts.grid.translated_fields", { locale }) }}
                </p>
                <AppTextarea v-model="bound.caption.value" :label="t('backend.posts.grid.social_text')" :rows="5" placeholder="Lumière du matin sur le port, sans retouche." />
            </div>
        </template>

        <template v-else-if="zone.type === 'qrCode'">
            <!-- The picture in the middle is shared, the address is not: the
                 English page's code should open the English page. -->
            <AppImagePickerField v-model="qrLogo" :label="t('backend.posts.grid.qr_logo')" />
            <p class="text-xs text-muted">{{ t("backend.posts.grid.qr_logo_hint") }}</p>
            <AppChoiceRow
                v-model="bound.size.value"
                :label="t('backend.posts.grid.qr_size')"
                :options="choices.size ?? []"
            />
            <AppToggle
                v-model="bound.qrDownload.value"
                :label="t('backend.posts.grid.qr_download')"
                :hint="t('backend.posts.grid.qr_download_hint')"
            />
            <div class="rounded-lg border border-dashed border-line p-3 space-y-2">
                <p class="text-xs uppercase tracking-wide text-muted">
                    {{ t("backend.posts.grid.translated_fields", { locale }) }}
                </p>
                <AppInput
                    v-model="bound.url.value"
                    :label="t('backend.posts.grid.qr_url')"
                    :hint="t('backend.posts.grid.qr_url_hint')"
                    placeholder="https://…"
                />
                <AppInput
                    v-model="bound.label.value"
                    placeholder="Scannez pour voir le site"
                    :label="t('backend.posts.grid.qr_label')"
                />
            </div>
        </template>

        <template v-else-if="zone.type === 'chart'">
            <AppChoiceRow
                v-model="bound.chartType.value"
                :label="t('backend.posts.grid.chart_type')"
                :options="choices.chartType ?? []"
            />
            <AppInput
                v-model="bound.chartUnit.value"
                :label="t('backend.posts.grid.chart_unit')"
                :hint="t('backend.posts.grid.chart_unit_hint')"
                placeholder="%"
            />
            <div class="rounded-lg border border-dashed border-line p-3 space-y-2">
                <p class="text-xs uppercase tracking-wide text-muted">
                    {{ t("backend.posts.grid.translated_fields", { locale }) }}
                </p>
                <AppInput v-model="bound.label.value" :label="t('backend.posts.grid.chart_title')" placeholder="Abonnés Instagram" />
                <AppTextarea
                    v-model="bound.code.value"
                    :label="t('backend.posts.grid.chart_data')"
                    :hint="t('backend.posts.grid.chart_data_hint')"
                    :placeholder="'Janvier ; 1200\nFévrier ; 1480\nMars ; 2100'"
                    :rows="6"
                />
                <AppInput v-model="bound.caption.value" :label="t('backend.posts.grid.chart_note')" placeholder="Source : statistiques du compte" />
            </div>
        </template>

        <template v-else-if="zone.type === 'videoWall'">
            <div class="flex items-center justify-between gap-3">
                <span class="text-sm text-secondary">
                    {{ t("backend.posts.grid.video_wall_count", { count: galleryImages.length, max: MAX_VIDEO_WALL }) }}
                </span>
                <AppButton
                    variant="secondary"
                    size="sm"
                    :disabled="galleryImages.length >= MAX_VIDEO_WALL"
                    v-on:click="pickWallVideos"
                >
                    <Plus class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ t("backend.posts.grid.video_wall_add") }}
                </AppButton>
            </div>
            <ul v-if="galleryImages.length" class="m-0 grid list-none grid-cols-3 gap-2 p-0">
                <li
                    v-for="(film, filmIndex) in galleryImages"
                    :key="`${filmIndex}-${film.url}`"
                    class="relative overflow-hidden rounded-lg border border-line bg-black"
                >
                    <video :src="film.url" muted preload="metadata" class="aspect-[9/16] w-full object-cover" />
                    <div class="absolute inset-x-0 bottom-0 flex justify-between gap-1 bg-surface/90 p-1">
                        <div class="flex gap-1">
                            <AppIconButton
                                :icon="ChevronUp"
                                size="sm"
                                :disabled="filmIndex === 0"
                                :title="t('backend.posts.grid.item_move_up')"
                                v-on:click="emit('move-gallery', filmIndex, -1)"
                            />
                            <AppIconButton
                                :icon="ChevronDown"
                                size="sm"
                                :disabled="filmIndex === galleryImages.length - 1"
                                :title="t('backend.posts.grid.item_move_down')"
                                v-on:click="emit('move-gallery', filmIndex, 1)"
                            />
                        </div>
                        <AppIconButton
                            :icon="Trash2"
                            size="sm"
                            color="danger"
                            :title="t('backend.posts.grid.gallery_remove')"
                            v-on:click="emit('remove-gallery', filmIndex)"
                        />
                    </div>
                </li>
            </ul>
            <p v-else class="text-sm text-muted">{{ t("backend.posts.grid.video_wall_empty") }}</p>
        </template>

        <template v-else-if="zone.type === 'editorialCalendar'">
            <AppDatePicker
                :model-value="bound.calendarMonth.value ?? ''"
                :month-only="true"
                :label="t('backend.posts.grid.calendar_month')"
                :hint="t('backend.posts.grid.calendar_month_hint')"
                v-on:update:model-value="(value) => (bound.calendarMonth.value = value || null)"
            />
            <div class="rounded-lg border border-dashed border-line p-3 space-y-2">
                <p class="text-xs uppercase tracking-wide text-muted">
                    {{ t("backend.posts.grid.translated_fields", { locale }) }}
                </p>
                <AppInput v-model="bound.label.value" :label="t('backend.posts.grid.calendar_title')" placeholder="Planning d'octobre" />
                <AppTextarea
                    v-model="bound.code.value"
                    :label="t('backend.posts.grid.calendar_entries')"
                    :hint="t('backend.posts.grid.calendar_entries_hint')"
                    :placeholder="'2026-10-03 | Instagram | Réel coulisses\n2026-10-07 | LinkedIn | Étude de cas'"
                    :rows="8"
                />
            </div>
        </template>

        <template v-else-if="zone.type === 'activityFeed'">
            <AppSelect
                v-model="bound.postTypeId.value"
                :label="t('backend.posts.grid.list_post_type')"
                :options="postTypeOptions"
                :placeholder="t('backend.posts.grid.list_any')"
            />
            <AppSelect
                v-model="bound.limit.value"
                :label="t('backend.posts.grid.list_limit')"
                :options="choices.limit ?? []"
            />
            <AppToggle
                v-model="bound.feedGithub.value"
                :label="t('backend.posts.grid.feed_github')"
                :hint="t('backend.posts.grid.feed_github_hint')"
            />
            <AppTextarea
                v-if="bound.feedGithub.value"
                :model-value="(bound.githubRepos.value ?? []).join('\n')"
                :label="t('backend.posts.grid.github_repos')"
                :hint="t('backend.posts.grid.github_repos_hint')"
                placeholder="AxelRaboit/aurora-core"
                :rows="3"
                v-on:update:model-value="(value) => (bound.githubRepos.value = parseLines(value))"
            />
        </template>

        <template v-else-if="zone.type === 'priceList'">
            <div class="rounded-lg border border-dashed border-line p-3 space-y-2">
                <p class="text-xs uppercase tracking-wide text-muted">
                    {{ t("backend.posts.grid.translated_fields", { locale }) }}
                </p>
                <AppInput v-model="bound.label.value" :label="t('backend.posts.grid.price_title')" placeholder="La carte" />
                <AppTextarea
                    v-model="bound.code.value"
                    :label="t('backend.posts.grid.price_lines')"
                    :hint="t('backend.posts.grid.price_lines_hint')"
                    :placeholder="'# Entrées\nSoupe du jour | 8 € | végétarien | selon le marché\nTartare de bœuf | 14 €'"
                    :rows="10"
                />
                <AppInput v-model="bound.caption.value" :label="t('backend.posts.grid.price_note')" placeholder="Prix nets, service compris" />
            </div>
        </template>

        <template v-else-if="zone.type === 'poll'">
            <AppChoiceRow
                v-model="bound.pollResults.value"
                :label="t('backend.posts.grid.poll_results')"
                :options="choices.pollResults ?? []"
            />
            <div class="rounded-lg border border-dashed border-line p-3 space-y-2">
                <p class="text-xs uppercase tracking-wide text-muted">
                    {{ t("backend.posts.grid.translated_fields", { locale }) }}
                </p>
                <AppInput v-model="bound.label.value" :label="t('backend.posts.grid.poll_question')" placeholder="Quel format préférez-vous ?" />
                <AppTextarea
                    v-model="bound.code.value"
                    :label="t('backend.posts.grid.poll_answers')"
                    :hint="t('backend.posts.grid.poll_answers_hint')"
                    :placeholder="'Les réels\nLes carrousels\nLes stories'"
                    :rows="5"
                />
            </div>
        </template>

        <template v-else-if="zone.type === 'travelMap'">
            <div class="flex items-center justify-between gap-3">
                <span class="text-sm text-secondary">
                    {{ t("backend.posts.grid.travel_photo_count", { count: galleryImages.length, max: MAX_TRAVEL_STOPS }) }}
                </span>
                <AppButton variant="secondary" size="sm" :disabled="galleryImages.length >= MAX_TRAVEL_STOPS" v-on:click="pickTravelPhotos">
                    <Plus class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ t("backend.posts.grid.travel_photo_add") }}
                </AppButton>
            </div>
            <ul v-if="galleryImages.length" class="m-0 grid list-none grid-cols-4 gap-2 p-0">
                <li v-for="(photo, photoIndex) in galleryImages" :key="`${photoIndex}-${photo.url}`" class="relative overflow-hidden rounded-lg border border-line">
                    <img :src="photo.url" alt="" class="aspect-square w-full object-cover">
                    <div class="absolute inset-x-0 bottom-0 flex justify-between gap-1 bg-surface/90 p-1">
                        <div class="flex gap-1">
                            <AppIconButton
                                :icon="ChevronUp"
                                size="sm"
                                :disabled="photoIndex === 0"
                                :title="t('backend.posts.grid.item_move_up')"
                                v-on:click="emit('move-gallery', photoIndex, -1)"
                            />
                            <AppIconButton
                                :icon="ChevronDown"
                                size="sm"
                                :disabled="photoIndex === galleryImages.length - 1"
                                :title="t('backend.posts.grid.item_move_down')"
                                v-on:click="emit('move-gallery', photoIndex, 1)"
                            />
                        </div>
                        <AppIconButton
                            :icon="Trash2"
                            size="sm"
                            color="danger"
                            :title="t('backend.posts.grid.gallery_remove')"
                            v-on:click="emit('remove-gallery', photoIndex)"
                        />
                    </div>
                </li>
            </ul>
            <div class="rounded-lg border border-dashed border-line p-3 space-y-2">
                <p class="text-xs uppercase tracking-wide text-muted">{{ t("backend.posts.grid.translated_fields", { locale }) }}</p>
                <AppTextarea
                    v-model="bound.code.value"
                    :label="t('backend.posts.grid.travel_stops')"
                    :hint="t('backend.posts.grid.travel_stops_hint')"
                    :placeholder="'Monument Valley | 36.9989 | -110.0980\nLondres | 51.5072 | -0.1276'"
                    :rows="6"
                />
            </div>
        </template>

        <template v-else-if="zone.type === 'quoteEstimator'">
            <AppInput v-model="bound.quoteCurrency.value" :label="t('backend.posts.grid.quote_currency')" placeholder="€" />
            <div class="rounded-lg border border-dashed border-line p-3 space-y-2">
                <p class="text-xs uppercase tracking-wide text-muted">{{ t("backend.posts.grid.translated_fields", { locale }) }}</p>
                <AppInput v-model="bound.label.value" :label="t('backend.posts.grid.quote_title')" placeholder="Estimez votre séance" />
                <AppTextarea
                    v-model="bound.code.value"
                    :label="t('backend.posts.grid.quote_options')"
                    :hint="t('backend.posts.grid.quote_options_hint')"
                    :placeholder="'= 90\nDrone | 40\nAlbum photo | 60'"
                    :rows="6"
                />
                <AppInput v-model="bound.caption.value" :label="t('backend.posts.grid.quote_note')" placeholder="Devis indicatif, confirmé après échange" />
            </div>
        </template>

        <template v-else-if="zone.type === 'appointmentBooking'">
            <OpeningHoursField v-model="bound.hours.value" />
            <AppTextarea
                :model-value="(bound.closedDates.value ?? []).join('\n')"
                :label="t('backend.posts.grid.closed_dates')"
                :hint="t('backend.posts.grid.closed_dates_hint')"
                placeholder="2026-12-25"
                :rows="3"
                v-on:update:model-value="(value) => (bound.closedDates.value = parseLines(value))"
            />
            <AppInput v-model="bound.timezone.value" :label="t('backend.posts.grid.timezone')" :hint="t('backend.posts.grid.timezone_hint')" placeholder="Europe/Paris" />
            <AppChoiceRow v-model="bound.slotDuration.value" :label="t('backend.posts.grid.slot_duration')" :options="choices.slotDuration ?? []" />
            <AppChoiceRow v-model="bound.bookingWindowDays.value" :label="t('backend.posts.grid.booking_window')" :options="choices.bookingWindowDays ?? []" />
            <div class="rounded-lg border border-dashed border-line p-3 space-y-2">
                <p class="text-xs uppercase tracking-wide text-muted">{{ t("backend.posts.grid.translated_fields", { locale }) }}</p>
                <AppInput v-model="bound.label.value" :label="t('backend.posts.grid.booking_title')" placeholder="Réserver une séance" />
                <AppInput v-model="bound.caption.value" :label="t('backend.posts.grid.booking_note')" placeholder="Une réponse sous 24 h" />
            </div>
        </template>

        <template v-else-if="zone.type === 'instagramFeed'">
            <p class="text-sm text-muted">{{ t("backend.posts.grid.instagram_feed_hint") }}</p>
            <AppChoiceRow v-model="bound.feedCount.value" :label="t('backend.posts.grid.feed_count')" :options="choices.feedCount ?? []" />
        </template>

        <template v-else-if="zone.type === 'googleReviews'">
            <p class="text-sm text-muted">{{ t("backend.posts.grid.google_reviews_hint") }}</p>
        </template>

        <template v-else-if="zone.type === 'newsletterSignup'">
            <p class="text-sm text-muted">{{ t("backend.posts.grid.newsletter_hint") }}</p>
            <div class="rounded-lg border border-dashed border-line p-3 space-y-2">
                <p class="text-xs uppercase tracking-wide text-muted">{{ t("backend.posts.grid.translated_fields", { locale }) }}</p>
                <AppInput v-model="bound.label.value" :label="t('backend.posts.grid.newsletter_title')" placeholder="Recevez les prochaines dates" />
                <AppInput v-model="bound.caption.value" :label="t('backend.posts.grid.newsletter_note')" placeholder="Une fois par mois, jamais de spam" />
            </div>
        </template>

        <template v-else-if="zone.type === 'deck'">
            <AppSelect
                v-model="bound.deckId.value"
                :label="t('backend.posts.grid.zone_deck')"
                :hint="t('backend.posts.grid.zone_deck_hint')"
                :options="deckOptions"
                :placeholder="t('backend.posts.grid.zone_deck_none')"
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

        <template v-else-if="zone.type === 'shared'">
            <!-- Shared, not translated, for the reason the publication zone
                 gives: the block carries its own translations and the page it
                 lands on picks the right one. -->
            <AppSelect
                v-model="bound.postId.value"
                :label="t('backend.posts.grid.zone_shared')"
                :hint="t('backend.posts.grid.zone_shared_hint')"
                :options="publicationOptions"
            />
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
            <AppChoiceRow
                v-model="bound.codeStyle.value"
                :label="t('backend.posts.grid.code_style')"
                :hint="t('backend.posts.grid.code_style_hint')"
                :options="choices.codeStyle ?? []"
            />
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
            <AppChoiceRow
                v-model="bound.galleryLayout.value"
                :label="t('backend.posts.grid.gallery_layout')"
                :hint="t('backend.posts.grid.gallery_layout_hint')"
                :options="choices.galleryLayout ?? []"
            />
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
            <AppChoiceRow
                v-model="bound.listLayout.value"
                :label="t('backend.posts.grid.list_layout')"
                :hint="t('backend.posts.grid.list_layout_hint')"
                :options="choices.listLayout ?? []"
            />
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
                    :label="bound.backgroundVideo.value ? t('backend.posts.grid.background_video_title') : t('backend.posts.grid.zone_caption')"
                    :placeholder="t('backend.posts.caption_placeholder')"
                />
                <template v-if="bound.media.value?.id && bound.backgroundVideo.value">
                    <AppInput
                        v-model="bound.label.value"
                        :label="t('backend.posts.grid.background_video_button')"
                        placeholder="Découvrir le lieu"
                    />
                    <AppInput
                        v-model="bound.url.value"
                        :label="t('backend.posts.grid.background_video_link')"
                        placeholder="/fr/page/contact"
                    />
                </template>
            </div>
            <!-- Only for a film of the library: a provider's player cannot be
                 made to play silently behind a title. -->
            <AppToggle
                v-if="bound.media.value?.id"
                v-model="bound.backgroundVideo.value"
                :label="t('backend.posts.grid.background_video')"
                :hint="t('backend.posts.grid.background_video_hint')"
            />
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
                <AppTextarea
                    v-model="bound.code.value"
                    :label="t('backend.posts.grid.episode_chapters')"
                    :hint="t('backend.posts.grid.episode_chapters_hint')"
                    :placeholder="'00:00 Introduction\n04:12 Le matériel\n---\nTranscription…'"
                    :rows="6"
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
