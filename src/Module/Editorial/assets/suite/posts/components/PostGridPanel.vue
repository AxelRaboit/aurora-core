<script setup>
/**
 * Content-grid panel of the post editor.
 *
 * A builder, like the banner's: add a zone, set how wide it is, reorder,
 * remove. Zones flow and wrap, so moving one is reordering it - there is no
 * empty cell to drag into.
 *
 * Two props because the grid is stored in two halves: the arrangement on the
 * post, what fills each zone on the open translation. The panel does not
 * arrange them differently for it - usePostGrid knows which half a field
 * belongs to.
 *
 * Presentation only: every field arrives as a writable computed, so nothing
 * here writes to a prop.
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from "vue";
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
import { BookmarkPlus, ChevronDown, ChevronUp, ClipboardPaste, Columns2, Copy, CopyPlus, Eye, LayoutTemplate, Monitor, Plus, Smartphone, Tablet, Trash2 } from "lucide-vue-next";
import { toast } from "vue-sonner";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import { useServerPreview } from "@/shared/composables/http/suite/useServerPreview.js";
import { placeZones, usePostGrid, ZONE_ICONS } from "../composables/usePostGrid.js";
import { openDocumentPicker } from "@/shared/utils/documentPicker.js";
import { useGridSelection } from "../composables/useGridSelection.js";
import { countPlaceholders } from "@/shared/utils/format/placeholders.js";
import { plainText } from "../composables/gridZoneSummary.js";
import PostGridCanvas from "./PostGridCanvas.vue";
import PostGridSaveSectionModal from "./PostGridSaveSectionModal.vue";
import PostGridSectionLibrary from "./PostGridSectionLibrary.vue";
import { withoutHiddenTypes } from "../composables/gridHiddenZones.js";
import { useGridClipboard } from "../composables/gridClipboard.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import PostGridZoneContent from "./PostGridZoneContent.vue";
import AppDatePicker from "@/shared/components/form/picker/AppDatePicker.vue";
import BannerColorField from "./BannerColorField.vue";

const props = defineProps({
    /** The arrangement, shared by every language. */
    layout: { type: Object, required: true },
    /** What fills each zone, for the language currently open. */
    content: { type: Object, required: true },
    /** Which language that is - shown on the fields that are per-language. */
    locale: { type: String, required: true },
    /** Publications this grid may link to, for the `post` zone type. */
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
    previewPath: { type: String, required: true },
    /** Where a header zone's panel asks for its preview. */
    bannerPreviewPath: { type: String, default: "" },
    /**
     * False where a page is always a grid, a client deliverable for one: the
     * on/off switch would only offer to empty it.
     */
    toggleable: { type: Boolean, default: true },
    /**
     * Zone types this host cannot render, left out of every type picker. A
     * deliverable has no comments, no site search and no post listing.
     */
    hiddenTypes: { type: Array, default: () => [] },
    /**
     * What else the host's page is made of, for the preview beside the
     * editor to be that page: a deliverable's title, appearance and header.
     * Sent with the grid; the server renders the whole page from it.
     */
    previewExtra: { type: Object, default: () => ({}) },
});

const { t } = useI18n();

// Held as one object as well as destructured: useGridSelection needs the write
// operations, and handing it the bag rather than nine arguments keeps the two
// composables from having to be edited in step every time one gains a verb.
const grid = usePostGrid(
    computed(() => props.layout),
    computed(() => props.content),
);

const {
    COLUMNS,
    zones,
    canAddZone,
    enabled,
    snap,
    snapOptions,
    reveal,
    revealOptions,
    rowGap,
    rowGapOptions,
    typeOptions: allTypeOptions,
    leafTypeOptions: allLeafTypeOptions,
    widthOptions,
    offsetOptions,
    shareOptions,
    ratioOptions,
    scaleOptions,
    alignOptions,
    childrenOf,
    canAddChild,
    addChild,
    removeChild,
    moveChild,
    childShare,
    zoneFields,
    zoneChoices,
    zoneItems,
    canAddItem,
    addItem,
    removeItem,
    moveItem,
    addGalleryImages,
    removeGalleryImage,
    moveGalleryImage,
    setCompareImage,
    itemFields,
    widthLabel,
    resizeZoneFromLeft: resizeZoneStart,
    swapZones,
    snapshotZones,
} = grid;

const visibleType = (option) => !props.hiddenTypes.includes(option.value);
const typeOptions = computed(() => allTypeOptions.value.filter(visibleType));
const leafTypeOptions = computed(() => allLeafTypeOptions.value.filter(visibleType));

const showPreview = ref(false);

/**
 * The preview beside the editor, kept up to date while typing: on a wide
 * screen the panel becomes two columns. The page is drawn at the width of
 * the device chosen and scaled down to the column, so a phone's layout is
 * seen as a phone's and not as a squeezed desktop.
 */
const SPLIT_KEY = "aurora.grid.split";
function readSplit() {
    try {
        return "1" === globalThis.localStorage?.getItem(SPLIT_KEY);
    } catch {
        return false;
    }
}
const split = ref(readSplit());
watch(split, (value) => {
    try {
        globalThis.localStorage?.setItem(SPLIT_KEY, value ? "1" : "0");
    } catch {
        // A refused storage only means the choice is not remembered.
    }
});

const DEVICES = [
    { key: "desktop", width: 1280, icon: Monitor },
    { key: "tablet", width: 820, icon: Tablet },
    { key: "phone", width: 390, icon: Smartphone },
];
const device = ref("desktop");
const deviceWidth = computed(() => DEVICES.find((entry) => entry.key === device.value)?.width ?? 1280);

const pane = ref(null);
const paneWidth = ref(0);
let paneObserver = null;
watch(pane, (element) => {
    paneObserver?.disconnect();
    if (!element || "undefined" === typeof ResizeObserver) return;
    paneObserver = new ResizeObserver(([entry]) => {
        paneWidth.value = entry.contentRect.width;
    });
    paneObserver.observe(element);
});
onBeforeUnmount(() => paneObserver?.disconnect());

const previewScale = computed(() => (paneWidth.value ? Math.min(1, paneWidth.value / deviceWidth.value) : 1));

/**
 * The document's sections: every text zone that starts a row with a main heading, in
 * order, with the blanks left from it to the next section. What the author
 * navigates by - « Objectifs de l'audit », « Benchmark » - rather than by the
 * fortieth box.
 */
const places = computed(() => placeZones(zones.value));

const outline = computed(() => {
    const sections = [];

    zones.value.forEach((zone, index) => {
        const held = props.content?.zones?.[zone.id];
        // A section opens on a text zone that starts its row with a main
        // heading - the rule GridSlides cuts a presentation by. A big figure
        // set as a heading in a card beside others does not start its row.
        const opensRow = 1 === places.value[index]?.column;
        const heading = "text" === zone.type && opensRow
            ? (held?.blocks ?? []).find((block) => "header" === block?.type && 2 === Number(block.data?.level ?? 2))
            : null;
        const title = heading ? plainText(heading.data?.text) : "";

        if (title) sections.push({ index, title, placeholders: 0, zonesWithPlaceholders: 0 });

        // A section counts the blanks of every zone down to the next
        // heading, where a tile counts its own: the zones are kept too, so
        // the badge can say why it holds more than the heading's tile.
        const current = sections.at(-1);
        const blanks = [zone, ...(zone.children ?? [])].reduce(
            (total, owner) => total + countPlaceholders(props.content?.zones?.[owner.id]),
            0,
        );
        if (current && blanks) {
            current.placeholders += blanks;
            current.zonesWithPlaceholders += 1;
        }
    });

    return sections;
});

/**
 * What a section's badge counts: its blanks, and, when they sit in more than
 * one zone, over how many - the reason it can read more than its heading's tile.
 */
function sectionPlaceholdersTitle(section) {
    const total = t("suite.posts.grid.section_placeholders", { count: section.placeholders });

    return section.zonesWithPlaceholders > 1
        ? `${total} ${t("suite.posts.grid.section_placeholders_zones", { count: section.zonesWithPlaceholders })}`
        : total;
}

/** The [blanks] still in this language's content, for the line above the canvas. */
const placeholders = computed(() => countPlaceholders(props.content?.zones));

// The locale travels too: a card links with `path('editorial_post', {locale})`
// and shows the linked publication in that language, so previewing the German
// tab from a French suite has to say German.
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

// The page beside the editor: the same request, asking for the whole page in
// the site's theme rather than the grid alone.
const { html: framePageHtml, loading: frameLoading } = useServerPreview(
    () => ({ layout: props.layout, content: props.content, locale: props.locale, frame: true, ...props.previewExtra }),
    [() => props.layout, () => props.content, () => props.locale, () => props.previewExtra],
    props.previewPath,
    { enabled: () => split.value },
);

/** Opens the library filtered to what a browser can play, like the video zone's own picker. */
async function pickBackgroundVideo(index) {
    const picked = await openDocumentPicker({ mimePrefix: "video/" });

    if (picked) zoneFields(index).backgroundVideo.value = picked;
}


// Which zone the canvas and the card below it are both pointing at, and what
// becomes of it when a zone is added, removed, reordered or relocated. Every
// answer is arithmetic on indices, which is why it is a composable and not six
// functions here - see useGridSelection.
const {
    selectedIndex,
    addZone: addAndSelect,
    addZoneOnNewRow,
    fillGap,
    removeZone: removeSelectedAware,
    moveZone: moveSelectedAware,
    moveZoneTo: moveAndSelect,
    moveIntoStack,
    moveOutOfStack,
    insertZones,
    duplicateZone,
} = useGridSelection(grid);

/** A click in the preview picks the zone it landed in - a stack's child picks its stack. */
function pickFromPreview(event) {
    const id = event.target.closest?.("[data-grid-zone]")?.getAttribute("data-grid-zone");
    if (!id) return;

    event.preventDefault();
    const index = zones.value.findIndex((zone) => zone.id === id || (zone.children ?? []).some((child) => child.id === id));
    if (-1 !== index) selectedIndex.value = index;
}

/**
 * The preview is a frame of its own, at the device's width: the page's
 * breakpoints answer to the frame and not to the back-office window, so a
 * phone is seen as a phone. It carries the back-office's stylesheets - the
 * same `app.css` the site loads - and the page's background and classes.
 */
const frame = ref(null);
const frameHeight = ref(300);

/**
 * The page as served, made still: its scripts go (they would be refused
 * under the back-office's policy anyway, and a preview has nothing to run),
 * links open elsewhere, and a style tag waits for the selected zone. What
 * is sized to the screen (`100vh`) is let go: the frame grows to fit its
 * page, and a page as tall as its frame would grow with it for ever.
 */
const frameDoc = computed(() =>
    String(framePageHtml.value ?? "")
        .replace(/<script\b[\s\S]*?<\/script>/gi, "")
        .replace(/<head([^>]*)>/i, '<head$1><base target="_blank"><style>body{cursor:pointer;min-height:0!important}.aurora-slide{min-height:0!important}</style><style data-highlight></style>'),
);

/** The selected zone, outlined in the frame. */
function paintHighlight() {
    const doc = frame.value?.contentDocument;
    const style = doc?.querySelector("style[data-highlight]");
    if (!style) return;

    const id = zones.value[selectedIndex.value]?.id;
    style.textContent = id ? `[data-grid-zone="${id}"] { outline: 2px solid var(--color-accent-500, #10b981); outline-offset: 4px; border-radius: 0.75rem; }` : "";
}

function onFrameLoad() {
    const doc = frame.value?.contentDocument;
    if (!doc) return;

    doc.addEventListener("click", pickFromPreview);
    frameHeight.value = Math.max(200, doc.body.scrollHeight);
    paintHighlight();
    // Pictures arrive after the frame: measure again when they have.
    doc.querySelectorAll("img").forEach((image) => image.addEventListener("load", () => {
        frameHeight.value = Math.max(200, doc.body.scrollHeight);
    }, { once: true }));
}

watch(selectedIndex, paintHighlight);
watch(deviceWidth, () => nextTick(onFrameLoad));


// Copying, pasting and keeping sections. The clipboard lives in the browser,
// so a zone copied in a publication can be pasted in a deliverable.
const { clipboard, copy: copyToClipboard } = useGridClipboard();
const showLibrary = ref(false);
const saving = ref(null);

/** Puts zones in after the selected one, or at the end when none is. */
function insertHere(payload) {
    // Without the zones this grid does not offer: a section pasted from a
    // publication into a deliverable must not carry a comments thread or a
    // form that the client's page would silently drop.
    const { payload: allowed, dropped } = withoutHiddenTypes(payload, props.hiddenTypes);

    insertZones(null === selectedIndex.value ? zones.value.length : selectedIndex.value + 1, allowed);
    showLibrary.value = false;

    if (dropped > 0) toast.message(t("suite.posts.grid.sections.dropped_hidden", { count: dropped }));
}

/** The zones of one section of the outline: from its heading to the next. */
function sectionSnapshot(position) {
    const start = outline.value[position].index;
    const end = outline.value[position + 1]?.index ?? zones.value.length;

    return snapshotZones(start, end - start);
}

function keep(payload, message) {
    if (copyToClipboard(payload)) toast.success(message);
}

function copyZone(index) {
    keep(snapshotZones(index), t("suite.posts.grid.sections.copied", { count: 1 }));
}

function copySection(position) {
    const payload = sectionSnapshot(position);
    keep(payload, t("suite.posts.grid.sections.copied", { count: payload.zones.length }));
}

function saveZone(index) {
    saving.value = { payload: snapshotZones(index), name: "" };
}

// A picture dropped on a box from the desktop: filed in the library like an
// upload from the picker, then placed - the picture of an image zone, one
// more in a gallery. Elsewhere it says where it can go.
const { request } = useRequest();
const { can } = usePrivileges();

async function dropFile(index, file) {
    const zone = zones.value[index];
    if (!["media", "gallery"].includes(zone?.type)) {
        toast.error(t("suite.posts.grid.drop_image_where"));

        return;
    }

    if (!can("ged.documents.create")) {
        toast.error(t("suite.posts.grid.drop_image_forbidden"));

        return;
    }

    const body = new FormData();
    body.append("file", file);
    const created = await request("/suite/ged/documents/upload-image", null, { rawBody: body });
    if (!created?.success) {
        toast.error(t("shared.media.upload_failed"));

        return;
    }

    const picked = { id: created.document.id, url: created.document.fileUrl, fileUrl: created.document.fileUrl };
    selectedIndex.value = index;
    if ("media" === zone.type) {
        zoneFields(index).media.value = picked;
    } else {
        addGalleryImages(index, [picked]);
    }
    toast.success(t("suite.posts.grid.drop_image_done"));
}

function saveSection(position) {
    saving.value = { payload: sectionSnapshot(position), name: outline.value[position].title };
}

const canvasHolder = ref(null);

/** Picks the section's first zone and brings its box into view. */
async function goToSection(index) {
    selectedIndex.value = index;
    await nextTick();
    canvasHolder.value?.querySelector(`[data-zone="${index}"]`)?.scrollIntoView({ behavior: "smooth", block: "center" });
}

/**
 * What the anchor is worth once it is on the page: the address to paste into a
 * link. The server slugs the name on save, so what is shown here is the shape
 * of the answer rather than the answer itself - which is why it is a hint and
 * not a read-only field.
 */
function anchorHint(index) {
    const anchor = zoneFields(index).anchor.value;

    return anchor
        ? t("suite.posts.grid.anchor_hint_set", { anchor })
        : t("suite.posts.grid.anchor_hint");
}

/** The canvas hands back an unrounded width; the one clamp lives downstream. */
function resizeZone(index, columns) {
    zoneFields(index).width.value = columns;
}

// No scrolling to the selected card, deliberately.
//
// Picking a zone used to scroll its fields into view, which made sense while
// every zone's card was on the page and the one you wanted was usually below
// the fold. Only one card shows now and it sits directly under the canvas, so
// there is nothing to go and find - and the scroll had become actively wrong:
// resizing selects the zone under the handle, and dropping selects the zone
// that moved, so both gestures ended by pulling the canvas off the screen just
// as the author was looking at what they had done.
</script>

<template>
    <div :class="split && enabled ? 'xl:grid xl:grid-cols-2 xl:items-start xl:gap-6' : ''">
        <div class="min-w-0 space-y-4">
            <AppToggle v-if="toggleable" v-model="enabled" :label="t('suite.posts.grid.enabled')" />

            <!-- Without this the card is a lone toggle, which reads as collapsed
             rather than as off. It also says the thing that matters: turning
             this on is what replaces the plain column, not something that
             renders beside it. -->
            <p v-if="!enabled" class="text-sm text-muted">
                {{ t("suite.posts.grid.disabled_hint") }}
            </p>

            <template v-if="enabled">
                <!-- The arrangement leads, because it is what the author works in:
                 pick a zone here and its fields appear underneath. Everything
                 that used to sit above it - the server preview - has moved
                 below, so reaching the controls costs no scrolling. -->
                <!-- The sections, by their headings: a long document is walked
                 through its titles. Folded once read; open by default. -->
                <details v-if="outline.length > 1" open class="rounded-lg border border-line bg-surface-2/30 px-3 py-2">
                    <summary class="cursor-pointer text-xs font-medium uppercase tracking-wide text-secondary">
                        {{ t("suite.posts.grid.outline", { count: outline.length }) }}
                    </summary>
                    <ol class="m-0 mt-2 grid list-none gap-x-4 gap-y-0.5 p-0 sm:grid-cols-2">
                        <!-- `min-w-0`: otherwise a grid item keeps the width of its content, and a long section title pushed both buttons off the screen on a phone (the page scrolled sideways). -->
                        <li v-for="(section, position) in outline" :key="section.index" class="flex min-w-0 items-center gap-1">
                            <button
                                type="button"
                                class="flex w-full min-w-0 items-baseline gap-2 rounded px-1.5 py-1 text-left text-sm hover:bg-surface-2"
                                :class="selectedIndex !== null && selectedIndex >= section.index && (outline[position + 1]?.index ?? Infinity) > selectedIndex ? 'text-accent font-medium' : 'text-primary'"
                                v-on:click="goToSection(section.index)"
                            >
                                <span class="w-5 shrink-0 text-right text-xs text-muted tabular-nums">{{ position + 1 }}</span>
                                <span class="min-w-0 flex-1 truncate">{{ section.title }}</span>
                                <span
                                    v-if="section.placeholders"
                                    class="shrink-0 rounded-full bg-amber-500/15 px-1.5 text-[10px] font-semibold text-amber-700 dark:text-amber-400 tabular-nums"
                                    :title="sectionPlaceholdersTitle(section)"
                                >[{{ section.placeholders }}]</span>
                            </button>
                            <span class="flex shrink-0 items-center">
                                <AppIconButton color="default" size="sm" :title="t('suite.posts.grid.sections.copy_section')" v-on:click="copySection(position)">
                                    <Copy class="w-3.5 h-3.5" :stroke-width="2" />
                                </AppIconButton>
                                <AppIconButton color="default" size="sm" :title="t('suite.posts.grid.sections.save_section')" v-on:click="saveSection(position)">
                                    <BookmarkPlus class="w-3.5 h-3.5" :stroke-width="2" />
                                </AppIconButton>
                            </span>
                        </li>
                    </ol>
                </details>

                <!-- Said once above the canvas; each tile carries its own count. -->
                <p
                    v-if="placeholders"
                    class="rounded-md bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-400"
                >
                    {{ t("suite.posts.grid.placeholders_left", { count: placeholders }) }}
                </p>
                <div ref="canvasHolder">
                    <PostGridCanvas
                        v-model:selected-index="selectedIndex"
                        :zones="zones"
                        :snap="snap"
                        :post-options="postOptions"
                        :type-options="typeOptions"
                        :can-add="canAddZone"
                        :content="content"
                        v-on:resize="resizeZone"
                        v-on:resize-start="resizeZoneStart"
                        v-on:add="addAndSelect"
                        v-on:add-at="addZoneOnNewRow"
                        v-on:fill-gap="fillGap"
                        v-on:swap="swapZones"
                        v-on:move="moveAndSelect"
                        v-on:move-into="moveIntoStack"
                        v-on:move-out="moveOutOfStack"
                        v-on:drop-file="dropFile"
                    />
                </div>

                <!-- Behind a button rather than inline: the panel is a column a few
                 hundred pixels wide, and a page laid out on 48 columns has
                 nothing useful to show at that size. Full width in a modal is
                 the first place the preview is actually to scale - and it gives
                 the editor back the room the preview was taking. -->
                <div class="flex flex-wrap gap-2">
                    <AppButton
                        variant="secondary"
                        size="sm"
                        type="button"
                        :disabled="!canAddZone"
                        v-on:click="showLibrary = true"
                    >
                        <LayoutTemplate class="w-4 h-4" :stroke-width="2" />
                        {{ t("suite.posts.grid.sections.title") }}
                    </AppButton>
                    <AppButton
                        v-if="clipboard"
                        variant="secondary"
                        size="sm"
                        type="button"
                        :disabled="!canAddZone"
                        v-on:click="insertHere(clipboard)"
                    >
                        <ClipboardPaste class="w-4 h-4" :stroke-width="2" />
                        {{ t("suite.posts.grid.sections.paste", { count: clipboard.zones.length }) }}
                    </AppButton>
                    <AppButton
                        class="hidden xl:inline-flex"
                        variant="secondary"
                        size="sm"
                        type="button"
                        :aria-pressed="split"
                        v-on:click="split = !split"
                    >
                        <Columns2 class="w-4 h-4" :stroke-width="2" />
                        {{ t(split ? "suite.posts.grid.split_off" : "suite.posts.grid.split_on") }}
                    </AppButton>
                    <AppButton
                        v-if="zones.length"
                        variant="secondary"
                        size="sm"
                        type="button"
                        v-on:click="showPreview = true"
                    >
                        <Eye class="w-4 h-4" :stroke-width="2" />
                        {{ t("suite.posts.grid.preview") }}
                    </AppButton>
                </div>

                <p v-if="zones.length && null === selectedIndex" class="text-sm text-muted">
                    {{ t("suite.posts.grid.pick_zone") }}
                </p>

                <!-- One zone's fields at a time, but every card stays mounted:
                 `v-show`, never `v-if`. Each text zone holds a live Editor.js,
                 and unmounting one loses its undo stack - the same reason the
                 locale tabs above this panel are `v-show` too.

                 focusin rather than a click target: typing in any field of a
                 card is the clearest statement that this is the zone being
                 worked on, and it keeps the canvas in step without adding a
                 control to reach for. -->
                <div
                    v-for="(zone, index) in zones"
                    v-show="selectedIndex === index"
                    :key="zone.id"
                    class="bg-surface-2/40 border border-accent rounded-lg p-2 space-y-4 sm:p-4"
                    v-on:focusin="selectedIndex = index"
                >
                    <div class="flex items-center gap-2">
                        <component :is="ZONE_ICONS[zone.type]" class="w-4 h-4 text-secondary" :stroke-width="2" />
                        <p class="text-sm font-medium text-primary flex-1">
                            {{ t(`suite.posts.grid.zone_types.${zone.type}`) }}
                        </p>
                        <AppIconButton
                            color="default"
                            :title="t('suite.posts.grid.move_up')"
                            :disabled="index === 0"
                            v-on:click="moveSelectedAware(index, -1)"
                        >
                            <ChevronUp class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>
                        <AppIconButton
                            color="default"
                            :title="t('suite.posts.grid.move_down')"
                            :disabled="index === zones.length - 1"
                            v-on:click="moveSelectedAware(index, 1)"
                        >
                            <ChevronDown class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>
                        <AppIconButton color="default" :title="t('suite.posts.grid.sections.duplicate')" :disabled="!canAddZone" v-on:click="duplicateZone(index)">
                            <CopyPlus class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>
                        <AppIconButton color="default" :title="t('suite.posts.grid.sections.copy_zone')" v-on:click="copyZone(index)">
                            <Copy class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>
                        <AppIconButton color="default" :title="t('suite.posts.grid.sections.save_zone')" v-on:click="saveZone(index)">
                            <BookmarkPlus class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>
                        <AppIconButton
                            color="rose"
                            :title="t('suite.posts.grid.remove_zone')"
                            v-on:click="removeSelectedAware(index)"
                        >
                            <Trash2 class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>
                    </div>

                    <!-- Converting rather than deleting and re-adding: the zone
                     keeps its id, so every other language keeps whatever it
                     holds for it, and the width and place in the order survive.
                     GridNormalizer writes every key whatever the type for
                     exactly this - a picture picked before a detour through
                     text is still picked on the way back. -->
                    <div class="space-y-1.5">
                        <AppChoiceRow
                            v-model="zoneFields(index).type.value"
                            :label="t('suite.posts.grid.zone_type')"
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
                            {{ t("suite.posts.grid.type_drops_text") }}
                        </p>
                    </div>

                    <!-- Named fractions rather than a slider: this is the keyboard
                     path to the same widths the canvas handle sets, and a button
                     that says "1/2" needs no picture of its own result. The
                     slider stays behind the disclosure for the widths no
                     fraction names - the summary keeps the exact count in view,
                     so a custom width is legible without opening anything. -->
                    <!-- Both hidden under full width, because the server sets
                     them: a band that spans the viewport is pushed back by half
                     its own container, which only lands on the middle of the
                     screen when the container is the whole row. Offering a
                     width whose value is then overruled is offering a control
                     that lies. -->
                    <div v-if="!zoneFields(index).fullBleed.value" class="space-y-1.5">
                        <AppChoiceRow
                            v-model="zoneFields(index).width.value"
                            :label="t('suite.posts.grid.width')"
                            :options="widthOptions"
                        />
                        <details>
                            <summary class="cursor-pointer text-xs text-muted marker:text-muted">
                                {{ t("suite.posts.grid.precise") }} - <span class="tabular-nums">{{ widthLabel(index) }}</span>
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

                    <!-- Where a zone sits on its row, as opposed to how much of
                     it it takes. Both only exist above the large breakpoint,
                     which is also the only place the canvas above draws rows -
                     below it every zone is full width and there is nothing to
                     push against.

                     Beneath the width on purpose: an offset is bounded by what
                     the width leaves, so the two read in the order they have to
                     be set in. -->
                    <div v-if="!zoneFields(index).fullBleed.value" class="space-y-1.5">
                        <AppChoiceRow
                            v-model="zoneFields(index).offset.value"
                            :label="t('suite.posts.grid.offset')"
                            :hint="t('suite.posts.grid.offset_hint')"
                            :options="offsetOptions"
                        />
                        <!-- The one arrangement an offset cannot express: a zone
                         that would fit beside its neighbour and should not.
                         Pushing it right leaves it on the same row; this is
                         what sends it below. -->
                        <AppToggle
                            v-model="zoneFields(index).newRow.value"
                            :label="t('suite.posts.grid.new_row')"
                            :hint="t('suite.posts.grid.new_row_hint')"
                        />
                    </div>

                    <!-- What the zone sits on, and whether that surface takes the
                     whole screen. Here rather than with the fields of one type,
                     because it belongs to every type - and beside the width,
                     because a background and a width are the same question
                     asked twice: how much room does this take, and where does
                     it stop.

                     Full width is top level only: inside a stack there is no
                     column to escape, and the normaliser would zero it. -->
                    <div class="space-y-1.5">
                        <AppChoiceRow
                            v-model="zoneFields(index).surface.value"
                            :label="t('suite.posts.grid.surface')"
                            :hint="t('suite.posts.grid.surface_hint')"
                            :options="zoneChoices.surface"
                        />

                        <!-- The one surface with a field of its own: a colour, a
                         gradient or a picture, exactly what the banner's own
                         editor already offers - so this reuses its fields
                         rather than inventing a second vocabulary for the
                         same choice. -->
                        <div
                            v-if="zoneFields(index).surface.value === 'custom'"
                            class="space-y-3 rounded-lg border border-dashed border-line p-3"
                        >
                            <div class="flex items-end gap-3">
                                <!-- A row of buttons rather than a native select,
                                 like `surface` itself just above: three named
                                 options are all on screen already, and a
                                 dropdown would hide two of them behind a click
                                 for no reason. -->
                                <AppChoiceRow
                                    v-model="zoneFields(index).fillType.value"
                                    :label="t('suite.posts.grid.fill')"
                                    :options="zoneChoices.fillType"
                                    class="flex-1"
                                />
                                <!-- Live swatch, same reasoning as the banner's
                                 own: the panel has no preview of the zone
                                 itself, and a gradient's direction is not
                                 something to discover after saving. -->
                                <span
                                    v-if="zoneFields(index).fillPreviewStyle.value"
                                    class="h-9 w-16 shrink-0 rounded-md border border-line"
                                    :style="zoneFields(index).fillPreviewStyle.value"
                                />
                            </div>

                            <BannerColorField
                                v-if="zoneFields(index).isSolidFill.value"
                                v-model="zoneFields(index).backgroundColor.value"
                                :label="t('suite.posts.grid.background_color')"
                            />

                            <template v-if="zoneFields(index).isGradientFill.value">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <BannerColorField
                                        v-model="zoneFields(index).gradientFrom.value"
                                        :label="t('suite.posts.grid.gradient_from')"
                                    />
                                    <BannerColorField
                                        v-model="zoneFields(index).gradientTo.value"
                                        :label="t('suite.posts.grid.gradient_to')"
                                    />
                                </div>
                                <div>
                                    <p class="text-sm text-secondary mb-1">
                                        {{ t('suite.posts.grid.gradient_angle', { degrees: zoneFields(index).gradientAngle.value }) }}
                                    </p>
                                    <AppRange v-model="zoneFields(index).gradientAngle.value" :min="0" :max="360" :step="15" />
                                </div>
                            </template>

                            <AppImagePickerField
                                v-model="zoneFields(index).backgroundMedia.value"
                                :label="t('suite.posts.grid.background_image')"
                                :hint="t('suite.posts.grid.background_image_hint')"
                            />

                            <!-- Muted and looped behind the content once picked -
                             independent of the still picture above, which
                             stays the fallback while it loads. -->
                            <div class="space-y-1">
                                <p class="text-xs uppercase tracking-wide text-muted">
                                    {{ t('suite.posts.grid.zone_background_video') }}
                                </p>
                                <div class="flex items-center gap-3">
                                    <span class="min-w-0 flex-1 truncate text-sm text-secondary">
                                        {{ zoneFields(index).backgroundVideo.value?.id
                                            ? t('suite.posts.grid.zone_video_file_chosen')
                                            : t('suite.posts.grid.zone_video_file_none') }}
                                    </span>
                                    <AppTextLinkButton size="xs" v-on:click="pickBackgroundVideo(index)">
                                        {{ zoneFields(index).backgroundVideo.value?.id
                                            ? t('shared.media.change')
                                            : t('suite.posts.grid.zone_video_file') }}
                                    </AppTextLinkButton>
                                    <AppTextLinkButton
                                        v-if="zoneFields(index).backgroundVideo.value?.id"
                                        color="danger"
                                        size="xs"
                                        v-on:click="zoneFields(index).backgroundVideo.value = null"
                                    >
                                        {{ t('shared.common.remove') }}
                                    </AppTextLinkButton>
                                </div>
                                <p class="text-xs text-muted">{{ t('suite.posts.grid.zone_background_video_hint') }}</p>
                            </div>

                            <div v-if="zoneFields(index).backgroundMedia.value?.id || zoneFields(index).backgroundVideo.value?.id">
                                <p class="text-sm text-secondary mb-1">
                                    {{ t('suite.posts.grid.overlay', { percent: zoneFields(index).overlay.value }) }}
                                </p>
                                <AppRange v-model="zoneFields(index).overlay.value" :min="0" :max="100" :step="5" />
                            </div>
                        </div>

                        <!-- Independent of the background: a card or a tint
                         still makes sense on a zone that also asks for the
                         opposite scheme, so this setting lives next to
                         "Fond" rather than in its custom panel. -->
                        <AppChoiceRow
                            v-model="zoneFields(index).contrast.value"
                            :label="t('suite.posts.grid.contrast')"
                            :hint="t('suite.posts.grid.contrast_hint')"
                            :options="zoneChoices.contrast"
                        />

                        <!-- Hovers and card markers of this zone, over the page's
                         and the theme's: a band of colour can ask for neutral
                         hovers while the rest of the page keeps its accent. -->
                        <AppChoiceRow
                            v-model="zoneFields(index).highlight.value"
                            :label="t('suite.posts.grid.highlight')"
                            :hint="t('suite.posts.grid.highlight_hint')"
                            :options="zoneChoices.highlight"
                        />
                        <BannerColorField
                            v-if="zoneFields(index).highlight.value === 'custom'"
                            v-model="zoneFields(index).highlightColor.value"
                            :label="t('suite.posts.grid.highlight_color')"
                        />

                        <!-- A zone that belongs to another trade than the page:
                         its button in the photographer's colour on a page
                         that is otherwise the agency's. -->
                        <!-- What speaks for the site rather than the page - a
                         contact, a closing call to action - keeps the site's
                         colour on a page dressed in one trade's. -->
                        <AppToggle
                            v-model="zoneFields(index).siteAccent.value"
                            :label="t('suite.posts.grid.site_accent')"
                            :hint="t('suite.posts.grid.site_accent_hint')"
                        />
                        <BannerColorField
                            v-if="!zoneFields(index).siteAccent.value"
                            v-model="zoneFields(index).accentColor.value"
                            :label="t('suite.posts.grid.accent_color')"
                            :hint="t('suite.posts.grid.accent_color_hint')"
                        />

                        <!-- How the zone arrives when the reader reaches
                         it. Here, with the background and the width, because
                         it is the same question asked a third time: how this
                         zone presents itself. No effect by default - a page
                         where everything moves is a page where nothing
                         stands out. -->
                        <AppChoiceRow
                            v-model="zoneFields(index).reveal.value"
                            :label="t('suite.posts.grid.reveal')"
                            :hint="t('suite.posts.grid.reveal_hint')"
                            :options="zoneChoices.reveal"
                        />
                        <AppChoiceRow
                            v-model="zoneFields(index).valign.value"
                            :label="t('suite.posts.grid.valign')"
                            :hint="t('suite.posts.grid.valign_hint')"
                            :options="zoneChoices.valign"
                        />
                        <AppChoiceRow
                            v-model="zoneFields(index).hideOn.value"
                            :label="t('suite.posts.grid.hide_on')"
                            :hint="t('suite.posts.grid.hide_on_hint')"
                            :options="zoneChoices.hideOn"
                        />
                        <AppChoiceRow
                            v-if="'none' !== zone.surface"
                            v-model="zoneFields(index).padding.value"
                            :label="t('suite.posts.grid.padding')"
                            :hint="t('suite.posts.grid.padding_hint')"
                            :options="zoneChoices.padding"
                        />
                        <AppToggle
                            v-model="zoneFields(index).sticky.value"
                            :label="t('suite.posts.grid.sticky')"
                            :hint="t('suite.posts.grid.sticky_hint')"
                        />
                        <AppToggle
                            v-model="zoneFields(index).fullBleed.value"
                            :label="t('suite.posts.grid.full_bleed')"
                            :hint="t('suite.posts.grid.full_bleed_hint')"
                        />
                        <p v-if="zoneFields(index).fullBleed.value" class="text-xs text-muted">
                            {{ t("suite.posts.grid.full_bleed_width_note") }}
                        </p>
                    </div>

                    <!-- A name a link can jump to. Kept with the arrangement
                     rather than with the fields of one type, because every
                     zone can be a destination and none of them is one by
                     default. The hint shows the address the name produces,
                     which is the thing the author is actually going to paste
                     into a button. -->
                    <AppInput
                        v-model="zoneFields(index).anchor.value"
                        :label="t('suite.posts.grid.anchor')"
                        :placeholder="t('suite.posts.grid.anchor_placeholder')"
                        :hint="anchorHint(index)"
                    />

                    <!-- When the zone is drawn, and for whom. Kept with the
                     arrangement and not with the fields of one type, because
                     every zone can be withheld and none of them is by default.
                     The panel always shows the zone - an author cannot arrange
                     what is hidden from them, and a zone waiting for its date
                     has to stay editable until it arrives. -->
                    <details class="rounded-lg border border-line p-3">
                        <summary class="cursor-pointer text-sm font-medium text-primary">
                            {{ t("suite.posts.grid.visibility") }}
                        </summary>
                        <div class="mt-3 space-y-3">
                            <p class="text-xs text-muted">{{ t("suite.posts.grid.visibility_hint") }}</p>
                            <div class="grid grid-cols-2 gap-3">
                                <AppDatePicker
                                    v-model="zoneFields(index).visibleFrom.value"
                                    :label="t('suite.posts.grid.visible_from')"
                                    :placeholder="t('suite.posts.grid.visible_any')"
                                />
                                <AppDatePicker
                                    v-model="zoneFields(index).visibleUntil.value"
                                    :label="t('suite.posts.grid.visible_until')"
                                    :placeholder="t('suite.posts.grid.visible_any')"
                                />
                            </div>
                            <AppChoiceRow
                                v-model="zoneFields(index).audience.value"
                                :label="t('suite.posts.grid.audience')"
                                :hint="t('suite.posts.grid.audience_hint')"
                                :options="zoneChoices.audience"
                            />
                        </div>
                    </details>

                    <!-- A stack holds zones instead of content, so it shows them
                     here: same fields, one level down. The share row is the
                     width row over again - inside a stack the axis of flow is
                     vertical, so a fraction reads as a fraction of the height
                     and the same six buttons say it. -->
                    <template v-if="zone.type === 'stack'">
                        <div class="space-y-3 rounded-lg border border-dashed border-line p-3">
                            <p class="text-xs uppercase tracking-wide text-muted">
                                {{ t("suite.posts.grid.stack_children") }}
                            </p>

                            <p v-if="!childrenOf(index).length" class="text-sm text-muted">
                                {{ t("suite.posts.grid.stack_empty") }}
                            </p>

                            <div
                                v-for="(child, childIndex) in childrenOf(index)"
                                :key="child.id"
                                class="aurora-card space-y-3 p-3"
                            >
                                <div class="flex items-center gap-2">
                                    <component :is="ZONE_ICONS[child.type]" class="w-4 h-4 text-secondary" :stroke-width="2" />
                                    <p class="flex-1 text-sm font-medium text-primary">
                                        {{ t(`suite.posts.grid.zone_types.${child.type}`) }}
                                    </p>
                                    <AppIconButton
                                        color="default"
                                        :title="t('suite.posts.grid.move_up')"
                                        :disabled="childIndex === 0"
                                        v-on:click="moveChild(index, childIndex, -1)"
                                    >
                                        <ChevronUp class="w-4 h-4" :stroke-width="2" />
                                    </AppIconButton>
                                    <AppIconButton
                                        color="default"
                                        :title="t('suite.posts.grid.move_down')"
                                        :disabled="childIndex === childrenOf(index).length - 1"
                                        v-on:click="moveChild(index, childIndex, 1)"
                                    >
                                        <ChevronDown class="w-4 h-4" :stroke-width="2" />
                                    </AppIconButton>
                                    <AppIconButton
                                        color="rose"
                                        :title="t('suite.posts.grid.remove_zone')"
                                        v-on:click="removeChild(index, childIndex)"
                                    >
                                        <Trash2 class="w-4 h-4" :stroke-width="2" />
                                    </AppIconButton>
                                </div>

                                <AppChoiceRow
                                    v-model="zoneFields(index, childIndex).type.value"
                                    :label="t('suite.posts.grid.zone_type')"
                                    :options="leafTypeOptions"
                                />

                                <div class="space-y-1.5">
                                    <AppChoiceRow
                                        v-model="zoneFields(index, childIndex).width.value"
                                        :label="t('suite.posts.grid.stack_share')"
                                        :options="shareOptions"
                                    />
                                    <!-- The fractions are grow factors against each
                                     other, not against 48, so two zones both set
                                     to 2/3 are two halves. This is the number
                                     that cannot say otherwise. -->
                                    <p class="text-xs text-muted">
                                        {{ t("suite.posts.grid.stack_share_effective", { percent: childShare(index, childIndex) }) }}
                                    </p>
                                </div>

                                <PostGridZoneContent
                                    :zone="child"
                                    :fields="zoneFields(index, childIndex)"
                                    :locale="locale"
                                    :post-options="postOptions"
                                    :post-type-options="postTypeOptions"
                                    :term-options="termOptions"
                                    :taxonomy-options="taxonomyOptions"
                                    :deck-options="deckOptions"
                                    :form-options="formOptions"
                                    :in-stack="true"
                                    :ratio-options="ratioOptions"
                                    :scale-options="scaleOptions"
                                    :align-options="alignOptions"
                                    :choices="zoneChoices"
                                    :banner-preview-path="bannerPreviewPath"
                                    :items="zoneItems(index, childIndex)"
                                    :item-fields="(i) => itemFields(index, i, childIndex)"
                                    :can-add-item="canAddItem(index, childIndex)"
                                    v-on:add-item="addItem(index, childIndex)"
                                    v-on:remove-item="(i) => removeItem(index, i, childIndex)"
                                    v-on:move-item="(i, d) => moveItem(index, i, d, childIndex)"
                                    v-on:add-gallery="(picked) => addGalleryImages(index, picked, childIndex)"
                                    v-on:remove-gallery="(i) => removeGalleryImage(index, i, childIndex)"
                                    v-on:move-gallery="(i, d) => moveGalleryImage(index, i, d, childIndex)"
                                    v-on:set-compare="(slot, picked) => setCompareImage(index, slot, picked, childIndex)"
                                />
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <AppButton
                                    v-for="option in leafTypeOptions"
                                    :key="option.value"
                                    variant="ghost"
                                    size="sm"
                                    type="button"
                                    :disabled="!canAddChild(index)"
                                    v-on:click="addChild(index, option.value)"
                                >
                                    <Plus class="w-4 h-4" :stroke-width="2" />
                                    {{ option.label }}
                                </AppButton>
                            </div>
                        </div>
                    </template>

                    <PostGridZoneContent
                        v-else
                        :zone="zone"
                        :fields="zoneFields(index)"
                        :locale="locale"
                        :post-options="postOptions"
                        :post-type-options="postTypeOptions"
                        :term-options="termOptions"
                        :taxonomy-options="taxonomyOptions"
                        :deck-options="deckOptions"
                        :form-options="formOptions"
                        :ratio-options="ratioOptions"
                        :scale-options="scaleOptions"
                        :align-options="alignOptions"
                        :choices="zoneChoices"
                        :banner-preview-path="bannerPreviewPath"
                        :items="zoneItems(index)"
                        :item-fields="(i) => itemFields(index, i)"
                        :can-add-item="canAddItem(index)"
                        v-on:add-item="addItem(index)"
                        v-on:remove-item="(i) => removeItem(index, i)"
                        v-on:move-item="(i, d) => moveItem(index, i, d)"
                        v-on:add-gallery="(picked) => addGalleryImages(index, picked)"
                        v-on:remove-gallery="(i) => removeGalleryImage(index, i)"
                        v-on:move-gallery="(i, d) => moveGalleryImage(index, i, d)"
                        v-on:set-compare="(slot, picked) => setCompareImage(index, slot, picked)"
                    />
                </div>

                <!-- How the page's zones arrive, answered once. A zone can
                 disagree, and one that never did follows this one for good -
                 the choice is stored as "inherit" rather than copied down, so
                 changing it here moves the whole page.

                 Out in the open and not under "advanced": it is a design
                 decision an author makes, not a lever they tune. -->
                <AppSelect
                    v-model="reveal"
                    :label="t('suite.posts.grid.page_reveal')"
                    :hint="t('suite.posts.grid.page_reveal_hint')"
                    :options="revealOptions"
                />
                <AppChoiceRow
                    v-model="rowGap"
                    :label="t('suite.posts.grid.row_gap')"
                    :hint="t('suite.posts.grid.row_gap_hint')"
                    :options="rowGapOptions"
                />

                <!-- The snap only governs the precise sliders now that fractions
                 carry the ordinary widths, so it sits with them rather than at
                 the top of the panel. One control for the whole layout, so it
                 stays here and not inside each zone. -->
                <details>
                    <summary class="cursor-pointer text-xs text-muted marker:text-muted">
                        {{ t("suite.posts.grid.advanced") }}
                    </summary>
                    <div class="pt-2">
                        <AppSelect
                            v-model="snap"
                            :label="t('suite.posts.grid.snap')"
                            :hint="t('suite.posts.grid.snap_hint')"
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
                    :title="t('suite.posts.grid.preview')"
                    :icon="Eye"
                    v-on:close="showPreview = false"
                >
                    <!-- The modal already has its sixteen pixels: sixteen more here would
                     make thirty-two on a phone, for a preview one looks at. -->
                    <div class="relative min-h-40 p-2 sm:p-4">
                        <div v-html="previewHtml" />
                        <AppLoader :active="previewLoading" />
                    </div>
                </AppModal>
            </template>
        </div>

        <!-- The preview beside the editor, sticky while the zones scroll. -->
        <aside v-if="split && enabled" class="hidden min-w-0 xl:sticky xl:top-20 xl:block">
            <div class="mb-2 flex items-center justify-between gap-2">
                <p class="m-0 text-xs font-medium uppercase tracking-wide text-secondary">{{ t("suite.posts.grid.preview") }}</p>
                <div class="flex gap-1">
                    <AppIconButton
                        v-for="entry in DEVICES"
                        :key="entry.key"
                        :active="device === entry.key"
                        :title="t(`suite.posts.grid.devices.${entry.key}`)"
                        v-on:click="device = entry.key"
                    >
                        <component :is="entry.icon" class="h-4 w-4" :stroke-width="2" />
                    </AppIconButton>
                </div>
            </div>
            <div
                ref="pane"
                data-preview-pane
                class="relative max-h-[calc(100vh-8rem)] overflow-y-auto overflow-x-hidden rounded-lg border border-line bg-surface"
            >
                <div class="mx-auto" :style="{ height: `${frameHeight * previewScale}px`, width: `${deviceWidth * previewScale}px` }">
                    <iframe
                        ref="frame"
                        :title="t('suite.posts.grid.preview')"
                        :srcdoc="frameDoc"
                        class="block border-0"
                        :style="{ width: `${deviceWidth}px`, height: `${frameHeight}px`, transform: `scale(${previewScale})`, transformOrigin: 'top left' }"
                        v-on:load="onFrameLoad"
                    />
                </div>
                <AppLoader :active="frameLoading" />
            </div>
        </aside>

        <PostGridSectionLibrary :show="showLibrary" v-on:close="showLibrary = false" v-on:insert="insertHere" />
        <PostGridSaveSectionModal
            :show="null !== saving"
            :payload="saving?.payload ?? null"
            :suggested-name="saving?.name ?? ''"
            v-on:close="saving = null"
        />
    </div>
</template>
