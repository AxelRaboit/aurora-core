<script setup>
/**
 * Content-grid panel of the post editor.
 *
 * A builder, like the banner's: add a zone, set how wide it is, reorder,
 * remove. Zones flow and wrap, so moving one is reordering it — there is no
 * empty cell to drag into.
 *
 * Two props because the grid is stored in two halves: the arrangement on the
 * post, what fills each zone on the open translation. The panel does not
 * arrange them differently for it — usePostGrid knows which half a field
 * belongs to.
 *
 * Presentation only: every field arrives as a writable computed, so nothing
 * here writes to a prop.
 */
import { computed, nextTick, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppBlockEditor from "@/shared/components/editor/AppBlockEditor.vue";
import AppImagePickerField from "@/shared/components/form/file/AppImagePickerField.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppRange from "@/shared/components/form/toggle/AppRange.vue";
import AppChoiceRow from "@/shared/components/form/select/AppChoiceRow.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import { ChevronDown, ChevronUp, Eye, FileText, Film, Image, Newspaper, Trash2 } from "lucide-vue-next";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import { useServerPreview } from "@/shared/composables/http/backend/useServerPreview.js";
import { usePostGrid } from "../composables/usePostGrid.js";
import PostGridCanvas from "./PostGridCanvas.vue";

const props = defineProps({
    /** The arrangement, shared by every language. */
    layout: { type: Object, required: true },
    /** What fills each zone, for the language currently open. */
    content: { type: Object, required: true },
    /** Which language that is — shown on the fields that are per-language. */
    locale: { type: String, required: true },
    /** Publications this grid may link to, for the `post` zone type. */
    postOptions: { type: Array, default: () => [] },
    previewPath: { type: String, required: true },
});

const { t } = useI18n();

const {
    COLUMNS,
    zones,
    canAddZone,
    enabled,
    snap,
    snapOptions,
    typeOptions,
    widthOptions,
    ratioOptions,
    addZone,
    removeZone,
    moveZone,
    swapZones,
    zoneFields,
    widthLabel,
} = usePostGrid(
    computed(() => props.layout),
    computed(() => props.content),
);

const showPreview = ref(false);

// The locale travels too: a card links with `path('editorial_post', {locale})`
// and shows the linked publication in that language, so previewing the German
// tab from a French backend has to say German.
//
// Gated on the modal being open: the preview now spends most of a session
// closed, and re-rendering Twig on every keystroke for markup nobody is looking
// at is work the server does for nothing. Opening asks immediately, and only if
// something has actually changed since the last answer.
const { html: previewHtml, loading: previewLoading } = useServerPreview(
    () => ({ layout: props.layout, content: props.content, locale: props.locale }),
    [() => props.layout, () => props.content, () => props.locale],
    props.previewPath,
    { enabled: () => showPreview.value },
);

const ZONE_ICONS = { text: FileText, media: Image, post: Newspaper, video: Film };

const publicationOptions = computed(() =>
    props.postOptions.map((post) => ({ value: post.id, label: post.title ?? `#${post.id}` })),
);

/**
 * Which zone the canvas and the list are both pointing at.
 *
 * UI state, so it lives here rather than in usePostGrid — nothing about it is
 * saved, and a composable that owns the document should not grow a field the
 * document does not have.
 */
const selectedIndex = ref(null);
const cardEls = ref([]);

/** The canvas hands back an unrounded width; the one clamp lives downstream. */
function resizeZone(index, columns) {
    zoneFields(index).width.value = columns;
}

// Only the selected zone shows its fields, so a zone added without being
// selected would land on the canvas and open nothing — it would read as the
// button having failed. `addZone` declines at the cap, hence the length check
// rather than assuming it worked.
function addAndSelect(type) {
    const before = zones.value.length;

    addZone(type);

    if (zones.value.length > before) {
        selectedIndex.value = zones.value.length - 1;
    }
}

// A zone that goes takes the selection with it, and a zone that moves keeps it:
// leaving an index behind would point the highlight at whichever zone slid into
// the slot.
function removeSelectedAware(index) {
    removeZone(index);

    if (selectedIndex.value === index) {
        selectedIndex.value = null;
    } else if (null !== selectedIndex.value && selectedIndex.value > index) {
        selectedIndex.value -= 1;
    }
}

function moveSelectedAware(index, offset) {
    const target = index + offset;

    if (target < 0 || target >= zones.value.length) {
        return;
    }

    moveZone(index, offset);

    if (selectedIndex.value === index) {
        selectedIndex.value = target;
    } else if (selectedIndex.value === target) {
        selectedIndex.value = index;
    }
}

// Picking a zone on the canvas should bring its fields into view — the list is
// long enough that the selected card is often below the fold.
watch(selectedIndex, async (index) => {
    if (null === index) {
        return;
    }

    await nextTick();
    cardEls.value[index]?.scrollIntoView({ block: "nearest", behavior: "smooth" });
});
</script>

<template>
    <div class="space-y-4">
        <AppToggle v-model="enabled" :label="t('backend.posts.grid.enabled')" />

        <!-- Without this the card is a lone toggle, which reads as collapsed
             rather than as off. It also says the thing that matters: turning
             this on is what replaces the plain column, not something that
             renders beside it. -->
        <p v-if="!enabled" class="text-sm text-muted">
            {{ t("backend.posts.grid.disabled_hint") }}
        </p>

        <template v-if="enabled">
            <!-- The arrangement leads, because it is what the author works in:
                 pick a zone here and its fields appear underneath. Everything
                 that used to sit above it — the server preview — has moved
                 below, so reaching the controls costs no scrolling. -->
            <PostGridCanvas
                v-model:selected-index="selectedIndex"
                :zones="zones"
                :snap="snap"
                :post-options="postOptions"
                :type-options="typeOptions"
                :can-add="canAddZone"
                v-on:resize="resizeZone"
                v-on:add="addAndSelect"
                v-on:swap="swapZones"
            />

            <!-- Behind a button rather than inline: the panel is a column a few
                 hundred pixels wide, and a page laid out on 48 columns has
                 nothing useful to show at that size. Full width in a modal is
                 the first place the preview is actually to scale — and it gives
                 the editor back the room the preview was taking. -->
            <div v-if="zones.length">
                <AppButton variant="secondary" size="sm" type="button" v-on:click="showPreview = true">
                    <Eye class="w-4 h-4" :stroke-width="2" />
                    {{ t("backend.posts.grid.preview") }}
                </AppButton>
            </div>

            <p v-if="zones.length && null === selectedIndex" class="text-sm text-muted">
                {{ t("backend.posts.grid.pick_zone") }}
            </p>

            <!-- One zone's fields at a time, but every card stays mounted:
                 `v-show`, never `v-if`. Each text zone holds a live Editor.js,
                 and unmounting one loses its undo stack — the same reason the
                 locale tabs above this panel are `v-show` too.

                 focusin rather than a click target: typing in any field of a
                 card is the clearest statement that this is the zone being
                 worked on, and it keeps the canvas in step without adding a
                 control to reach for. -->
            <div
                v-for="(zone, index) in zones"
                v-show="selectedIndex === index"
                :key="zone.id"
                :ref="(el) => (cardEls[index] = el)"
                class="bg-surface-2/40 border border-accent rounded-lg p-4 space-y-4"
                v-on:focusin="selectedIndex = index"
            >
                <div class="flex items-center gap-2">
                    <component :is="ZONE_ICONS[zone.type]" class="w-4 h-4 text-secondary" :stroke-width="2" />
                    <p class="text-sm font-medium text-primary flex-1">
                        {{ t(`backend.posts.grid.zone_types.${zone.type}`) }}
                    </p>
                    <AppIconButton
                        color="default"
                        :title="t('backend.posts.grid.move_up')"
                        :disabled="index === 0"
                        v-on:click="moveSelectedAware(index, -1)"
                    >
                        <ChevronUp class="w-4 h-4" :stroke-width="2" />
                    </AppIconButton>
                    <AppIconButton
                        color="default"
                        :title="t('backend.posts.grid.move_down')"
                        :disabled="index === zones.length - 1"
                        v-on:click="moveSelectedAware(index, 1)"
                    >
                        <ChevronDown class="w-4 h-4" :stroke-width="2" />
                    </AppIconButton>
                    <AppIconButton
                        color="rose"
                        :title="t('backend.posts.grid.remove_zone')"
                        v-on:click="removeSelectedAware(index)"
                    >
                        <Trash2 class="w-4 h-4" :stroke-width="2" />
                    </AppIconButton>
                </div>

                <!-- Converting rather than deleting and re-adding: the zone
                     keeps its id, so every other language keeps whatever it
                     holds for it, and the width and place in the order survive.
                     GridNormalizer writes every key whatever the type for
                     exactly this — a picture picked before a detour through
                     text is still picked on the way back. -->
                <div class="space-y-1.5">
                    <AppChoiceRow
                        v-model="zoneFields(index).type.value"
                        :label="t('backend.posts.grid.zone_type')"
                        :options="typeOptions"
                    />
                    <!-- Only a warning, never a block: the blocks are still in
                         the editor's state and coming back to text restores
                         them. It is saving in another type that drops them, on
                         the server, and that is worth saying before it happens
                         rather than after. -->
                    <p
                        v-if="zone.type !== 'text' && zoneFields(index).blocks.value?.length"
                        class="text-xs text-amber-600 dark:text-amber-500"
                    >
                        {{ t("backend.posts.grid.type_drops_text") }}
                    </p>
                </div>

                <!-- Named fractions rather than a slider: this is the keyboard
                     path to the same widths the canvas handle sets, and a button
                     that says "1/2" needs no picture of its own result. The
                     slider stays behind the disclosure for the widths no
                     fraction names — the summary keeps the exact count in view,
                     so a custom width is legible without opening anything. -->
                <div class="space-y-1.5">
                    <AppChoiceRow
                        v-model="zoneFields(index).width.value"
                        :label="t('backend.posts.grid.width')"
                        :options="widthOptions"
                    />
                    <details>
                        <summary class="cursor-pointer text-xs text-muted marker:text-muted">
                            {{ t("backend.posts.grid.precise") }} — <span class="tabular-nums">{{ widthLabel(index) }}</span>
                        </summary>
                        <div class="pt-2">
                            <AppRange
                                v-model="zoneFields(index).width.value"
                                :min="snap"
                                :max="COLUMNS"
                                :step="snap"
                            />
                        </div>
                    </details>
                </div>

                <template v-if="zone.type === 'text'">
                    <div class="rounded-lg border border-dashed border-line p-3 space-y-2">
                        <p class="text-xs uppercase tracking-wide text-muted">
                            {{ t("backend.posts.grid.translated_fields", { locale }) }}
                        </p>
                        <AppBlockEditor
                            v-model="zoneFields(index).blocks.value"
                            :placeholder="t('backend.posts.content_placeholder')"
                        />
                    </div>
                </template>

                <template v-else-if="zone.type === 'media'">
                    <AppImagePickerField
                        v-model="zoneFields(index).media.value"
                        :label="t('backend.posts.grid.zone_image')"
                    />
                    <!-- The one vertical control the grid has, and the only one
                         it will get: a shape to crop to, not a height. Shared
                         rather than per language, like the span — how a picture
                         is cropped is design, written once. -->
                    <AppChoiceRow
                        v-model="zoneFields(index).ratio.value"
                        :label="t('backend.posts.grid.ratio')"
                        :hint="t('backend.posts.grid.ratio_hint')"
                        :options="ratioOptions"
                    />
                    <div class="rounded-lg border border-dashed border-line p-3 space-y-4">
                        <p class="text-xs uppercase tracking-wide text-muted">
                            {{ t("backend.posts.grid.translated_fields", { locale }) }}
                        </p>
                        <AppInput
                            v-model="zoneFields(index).alt.value"
                            :label="t('backend.posts.grid.zone_alt')"
                        />
                        <AppInput
                            v-model="zoneFields(index).caption.value"
                            :label="t('backend.posts.grid.zone_caption')"
                        />
                    </div>
                </template>

                <template v-else-if="zone.type === 'post'">
                    <!-- Shared, not translated: the linked publication carries
                         its own translations and the page picks the right one. -->
                    <AppSelect
                        v-model="zoneFields(index).postId.value"
                        :label="t('backend.posts.grid.zone_post')"
                        :hint="t('backend.posts.grid.zone_post_hint')"
                        :options="publicationOptions"
                    />
                </template>

                <template v-else>
                    <div class="rounded-lg border border-dashed border-line p-3 space-y-4">
                        <p class="text-xs uppercase tracking-wide text-muted">
                            {{ t("backend.posts.grid.translated_fields", { locale }) }}
                        </p>
                        <!-- The address is per language: a localised video has
                             a localised URL. -->
                        <AppInput
                            v-model="zoneFields(index).url.value"
                            :label="t('backend.posts.grid.zone_video')"
                            :hint="t('backend.posts.grid.zone_video_hint')"
                            placeholder="https://youtu.be/…"
                        />
                        <AppInput
                            v-model="zoneFields(index).caption.value"
                            :label="t('backend.posts.grid.zone_caption')"
                        />
                    </div>
                </template>
            </div>

            <!-- The snap only governs the precise sliders now that fractions
                 carry the ordinary widths, so it sits with them rather than at
                 the top of the panel. One control for the whole layout, so it
                 stays here and not inside each zone. -->
            <details>
                <summary class="cursor-pointer text-xs text-muted marker:text-muted">
                    {{ t("backend.posts.grid.advanced") }}
                </summary>
                <div class="pt-2">
                    <AppSelect
                        v-model="snap"
                        :label="t('backend.posts.grid.snap')"
                        :hint="t('backend.posts.grid.snap_hint')"
                        :options="snapOptions"
                    />
                </div>
            </details>

            <!-- Rendered by the server from the same Twig the public page uses,
                 so what shows here is what gets published. `no-padding` because
                 the grid brings its own gutters, and adding the modal's would
                 shift the two outer edges the way `.aurora-grid-flush` exists
                 to prevent. -->
            <AppModal
                :show="showPreview"
                max-width="full"
                mobile-fullscreen
                no-padding
                :title="t('backend.posts.grid.preview')"
                :icon="Eye"
                v-on:close="showPreview = false"
            >
                <div class="relative min-h-40 p-4">
                    <div v-html="previewHtml" />
                    <AppLoader :active="previewLoading" />
                </div>
            </AppModal>
        </template>
    </div>
</template>
