<script setup>
/**
 * Composing one deck: the slides on the left, the one being written on the
 * right.
 *
 * **No Save button, and that is deliberate.** A deck is edited by hopping from
 * slide to slide, and every hop is a moment where the work on the one being
 * left is finished. Saving there costs the reader nothing to remember; a
 * button would be one more thing to forget before closing the tab.
 *
 * The preview is the same component the thumbnails use, at the same fixed
 * 16:9. A preview that reflowed would be a preview that lies about what lands
 * on the wall, which is the whole reason this module has layouts rather than a
 * flowing grid.
 */
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { VueDraggable } from "vue-draggable-plus";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useDeckEditor } from "./composables/useDeckEditor.js";
import { useDeckSharing } from "./composables/useDeckSharing.js";
import { useDeckAppearance } from "./composables/useDeckAppearance.js";
import { useDeckChapters } from "./composables/useDeckChapters.js";
import SlideFrame from "./components/SlideFrame.vue";
import DeckPlayer from "./components/DeckPlayer.vue";
import DeckAppearancePanel from "./components/DeckAppearancePanel.vue";
import FreeCanvas from "./free/FreeCanvas.vue";
import FreeInsertBar from "./free/FreeInsertBar.vue";
import FreeInspector from "./free/FreeInspector.vue";
import FreeLayers from "./free/FreeLayers.vue";
import { useFreeEditor } from "./free/useFreeEditor.js";
import { freeFromDrawn } from "./free/fromTemplate.js";
import { registerUploadedFonts } from "./free/fonts.js";
import { toast } from "vue-sonner";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import AppPageBar from "@/shared/components/nav/AppPageBar.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppImagePickerField from "@/shared/components/form/file/AppImagePickerField.vue";
import AppRange from "@/shared/components/form/toggle/AppRange.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import AppFocalPointField from "@/shared/components/form/file/AppFocalPointField.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import {
    ArrowDown,
    ArrowUp,
    ChevronDown,
    ChevronRight,
    Copy,
    CopyPlus,
    GripVertical,
    MonitorSpeaker,
    Palette,
    Play,
    Plus,
    Presentation,
    Printer,
    Redo2,
    Undo2,
    WandSparkles,
    Share2,
    Trash2,
    X,
} from "lucide-vue-next";

const { t, d } = useI18n();
const { can } = usePrivileges();

const props = defineProps({
    deck: { type: Object, required: true },
    /** The deck list, for the page bar's back link. */
    backPath: { type: String, default: null },
    layouts: { type: Array, default: () => [] },
    /** Slots every layout accepts: the line above the title, and the backdrop. */
    commonSlots: { type: Array, default: () => [] },
    /** Slots that hold one line per row rather than one string. */
    listSlots: { type: Array, default: () => [] },
    /** What a free slide's elements may be: shapes, masks, entrances, bounds. */
    freeOptions: { type: Object, default: () => ({}) },
    /** Fonts uploaded to the library, offered by every text box. */
    uploadedFonts: { type: Array, default: () => [] },
    fontUploadPath: { type: String, default: "" },
    slideCreatePath: { type: String, required: true },
    slideUpdatePath: { type: String, required: true },
    slideDeletePath: { type: String, required: true },
    slideDuplicatePath: { type: String, required: true },
    slideReorderPath: { type: String, required: true },
    printPath: { type: String, required: true },
    presenterPath: { type: String, required: true },
    shareLinks: { type: Array, default: () => [] },
    /** Pictures on this deck that a link's holder would not be served. */
    withheldPictures: { type: Array, default: () => [] },
    shareCreatePath: { type: String, required: true },
    shareRevokePath: { type: String, required: true },
    /** Deleting an address nobody ever opened; an opened one is only revoked. */
    shareDeletePath: { type: String, required: true },
    themes: { type: Array, default: () => [] },
    fontPairs: { type: Array, default: () => [] },
    logoPlacements: { type: Array, default: () => [] },
    looks: { type: Array, default: () => [] },
    gradients: { type: Array, default: () => [] },
    patterns: { type: Array, default: () => [] },
    margins: { type: Array, default: () => [] },
    titleCases: { type: Array, default: () => [] },
    bulletShapes: { type: Array, default: () => [] },
    transitions: { type: Array, default: () => [] },
    appearancePath: { type: String, required: true },
});

const {
    slides,
    selectedId,
    selected,
    slots,
    dirty,
    saving,
    pendingDelete,
    select,
    addSlide,
    confirmDeleteSlide,
    duplicateSlide,
    move,
    reorder,
    writeSlot,
    writeLayout,
    writeSlide,
    writeNotes,
    flushCurrent,
} = useDeckEditor(props);

const editable = can("studio.decks.edit");


/**
 * The chapters, read off the section slides rather than stored.
 *
 * Folding one hides its slides without taking them out of the list the
 * drag-and-drop reorders: a filtered list would come back short and save that
 * as the new order.
 */
const { isHidden, isFolded, sizes, toggle: toggleChapter, foldable } = useDeckChapters(slides);

/**
 * The deck's look, held here because every frame on the page draws with it.
 *
 * `appearance` is the saved answer and `preview` the one being composed; the
 * panel shows the second, everything else shows the first. A page that
 * previewed everywhere would repaint thirty thumbnails on every drag of a
 * colour slider, for a decision that is being made in one frame.
 */
const {
    open: appearanceOpen,
    saving: savingAppearance,
    appearance,
    theme,
    style,
    logo,
    inherited,
    isOverridden,
    carriesOverrides,
    preview,
    resetColours,
    applyLook,
    write: writeStyle,
    writeLogo,
    save: saveAppearance,
} = useDeckAppearance(props);

/**
 * A free slide is edited on the slide itself rather than through a form.
 *
 * The canvas, the bar that adds to it and the panel that sets what is picked
 * all work through one editor, which keeps the selection and a history per
 * slide; the deck's own saving is unchanged, a slide still goes to the server
 * when it is left.
 */
const isFree = computed(() => selected.value?.layout === "free");

const freeEditor = useFreeEditor({
    slide: selected,
    writeSlide,
    maxElements: props.freeOptions?.maxElements ?? 200,
});

/**
 * Saved as it is worked on, a moment after the last change.
 *
 * Saving on leaving is right for a form, where the words are the work; on a
 * canvas an hour can go by on one slide, and a closed laptop lid would take
 * all of it. A second and a half after the hand stops is long enough not to
 * save in the middle of a drag.
 */
let autosave = null;

/** A save that found another under way, or changes made during it, tries again. */
async function saveSoon() {
    clearTimeout(autosave);
    autosave = setTimeout(async () => {
        await flushCurrent();

        if (isFree.value && (dirty.value || saving.value)) saveSoon();
    }, 1500);
}

watch(
    () => (isFree.value ? JSON.stringify(selected.value?.content) : null),
    (now, before) => {
        if (now && now !== before) saveSoon();
    },
);

/** The editor's own preview, which is what a conversion reads. */
const previewFrame = ref(null);

/**
 * Turn the slide on screen into a free one that looks the same.
 *
 * Recorded first, so the step back is the laid-out slide, words and layout
 * together: the one way back there is, since a free slide cannot be guessed
 * back into slots.
 */
function convertToFree() {
    const frame = previewFrame.value?.querySelector(".slide-frame");

    if (!frame || !selected.value || isFree.value) return;

    freeEditor.checkpoint();
    writeSlide(freeFromDrawn(frame, selected.value, appearance.value));
    toast.success(t("backend.studio.decks.free.converted"));
}

/** Choosing "free" in the layout list converts, rather than emptying the slide. */
function pickLayout(value) {
    if (value === "free") {
        convertToFree();

        return;
    }

    writeLayout(value);
}

/** Whether the list of layouts to add is unfolded, on a narrow screen. */
const addingOpen = ref(false);

registerUploadedFonts(props.uploadedFonts);

/**
 * A font file, sent to the library and set on the selected text at once.
 *
 * A plain multipart post rather than the JSON helper: the body is a file.
 */
const fontUploading = ref(false);
const canUploadFont = computed(() => editable && can("ged.documents.create") && !!props.fontUploadPath);

async function uploadFont(file) {
    if (!file || fontUploading.value) return;

    fontUploading.value = true;

    try {
        const body = new FormData();
        body.append("file", file);

        const response = await fetch(props.fontUploadPath, {
            method: "POST",
            headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
            body,
        });
        const data = await response.json().catch(() => null);

        if (!response.ok || !data?.success || !data.font) {
            toast.error(t(data?.error ?? "backend.studio.decks.free.font_errors.refused"));

            return;
        }

        registerUploadedFonts([data.font]);
        freeEditor.patchSelected({ font: data.font.key });
        toast.success(t("backend.studio.decks.free.font_uploaded_ok", { name: data.font.name }));
    } finally {
        fontUploading.value = false;
    }
}

function insertElement(type, overrides) {
    const element = freeEditor.add(type, overrides);

    if (element?.type === "text" && !element.html) freeEditor.startEditing(element.id);
}

/**
 * Presenting saves first.
 *
 * Going full screen on a slide whose last sentence is still only in the form
 * is the one moment where the absent Save button would be felt.
 */
const playing = ref(false);
const playFrom = computed(() =>
    Math.max(slides.value.findIndex((slide) => slide.id === selectedId.value), 0),
);

async function present() {
    await flushCurrent();
    playing.value = true;
}

/**
 * The notes, on the other screen.
 *
 * Opened before the player rather than from inside it, because the window the
 * browser opens takes focus and would drop the full screen the player just
 * asked for. Opening it first leaves the reader one click from presenting, on
 * the screen they were already looking at.
 */
async function openPresenter() {
    await flushCurrent();
    window.open(props.presenterPath, `deck-presenter-${props.deck.id}`, "noopener");
}

async function print() {
    await flushCurrent();
    window.open(`${props.printPath}?print=1`, "_blank", "noopener");
}

const {
    sharing,
    links,
    newLabel,
    expiresInDays,
    newPassword,
    withheld,
    creating,
    createLink,
    revoke,
    remove,
    copy,
    copiedId,
    isLive,
    isDeletable,
} = useDeckSharing(props);

/**
 * The four the header does not need to spell out.
 *
 * Presenting is what a deck is for and keeps its own button; the rest are the
 * ways around it - the other screen, paper, a link, the look - and they were
 * five buttons wide on a page whose left column is already a list of slides.
 *
 * Presenting on paper or on the second screen needs a slide to show, the same
 * condition the main button carries.
 */
const deckActions = computed(() => {
    const actions = [
        {
            key: "presenter",
            icon: MonitorSpeaker,
            title: t("backend.studio.decks.presenter"),
            disabled: !slides.value.length,
            onSelect: openPresenter,
        },
        {
            key: "print",
            icon: Printer,
            title: t("backend.studio.decks.print"),
            disabled: !slides.value.length,
            onSelect: print,
        },
    ];

    if (can("studio.decks.share")) {
        actions.push({
            key: "share",
            icon: Share2,
            title: t("backend.studio.decks.share"),
            onSelect: () => (sharing.value = true),
        });
    }

    if (editable) {
        actions.push({
            key: "appearance",
            color: "accent",
            icon: Palette,
            title: t("backend.studio.decks.appearance"),
            onSelect: () => (appearanceOpen.value = true),
        });
    }

    return actions;
});

const layoutOptions = props.layouts.map((layout) => ({
    value: layout.value,
    label: t(layout.labelKey),
}));

/** A free slide is not turned back into a layout: there is no list to show. */
const freeLabel = computed(() => t("backend.studio.decks.layouts.free"));

const labelFor = (slot) => t(`backend.studio.decks.slots.${slot}`);

/**
 * The picture slot, as the picker speaks it.
 *
 * The model stores an id and nothing else; the picker wants `{id, url}` and the
 * frame wants the address to draw. The write therefore puts both in the
 * content, and the manager drops the address on save: `mediaUrl` is not one of
 * the layout's slots, so it never reaches the database. The address of a
 * picture changes when its file does, and a copy of it kept in the slide would
 * be a second truth to maintain.
 */
const picture = () => ({
    id: selected.value?.content.mediaId ?? null,
    url: selected.value?.content.mediaUrl ?? null,
});

function writePicture(value) {
    if (!selected.value) return;

    writeSlot("mediaId", value?.id ?? null);
    writeSlot("mediaUrl", value?.url ?? null);
}

/**
 * How the picture fills its box, and the point it is cropped around.
 *
 * The focal field speaks in fractions and the slide stores an
 * `object-position` string, because that is what the frame writes into CSS and
 * a pair of floats in the content would be two slots to keep in agreement.
 */
const fitOptions = computed(() => [
    { value: "contain", label: t("backend.studio.decks.media_fit_contain") },
    { value: "cover", label: t("backend.studio.decks.media_fit_cover") },
]);

/**
 * Les cases du sélecteur multiple : celles qui portent une image, plus une vide.
 *
 * Le composant de choix d'image existe et sait en prendre une. Plutôt que d'en
 * écrire un second qui en prendrait plusieurs, on le répète : une case par
 * image déjà choisie, et une de plus pour la suivante. Le rang d'une case est
 * sa place dans l'arrangement, donc vider la deuxième resserre les autres
 * plutôt que de laisser un trou.
 */
const pictureRows = computed(() => {
    const ids = selected.value?.content?.mediaIds ?? [];
    const urls = selected.value?.content?.mediaPictures ?? [];

    return [
        ...ids.map((id, at) => ({ at, id, url: urls[at]?.url ?? null })),
        { at: ids.length, id: null, url: null },
    ].slice(0, 8);
});

function writePictureAt(at, value) {
    const ids = [...(selected.value?.content?.mediaIds ?? [])];
    const drawn = [...(selected.value?.content?.mediaPictures ?? [])];

    if (value?.id) {
        ids[at] = value.id;
        drawn[at] = { url: value.url ?? null, alt: "", focus: "50% 50%" };
    } else {
        ids.splice(at, 1);
        drawn.splice(at, 1);
    }

    const kept = ids.filter((id) => typeof id === "number" && id > 0);

    writeSlot("mediaIds", kept);

    // L'adresse est ecrite a cote de l'identifiant, comme le font deja les deux
    // autres champs d'image de cet ecran : la slide n'est relue du serveur que
    // lorsqu'on la quitte, et sans ca la case choisie redevenait vide et
    // l'apercu restait blanc jusqu'a ce qu'on aille voir ailleurs.
    writeSlot("mediaPictures", drawn.slice(0, kept.length));
}

/** Les trois listes de composition, avec leur valeur d'aujourd'hui en tête. */
const compositionOptions = (slot, values) =>
    computed(() =>
        values.map((value) => ({
            value,
            label: t(`backend.studio.decks.${slot}s.${value}`),
        })),
    );

const anchorOptions = compositionOptions("anchor", ["center", "top", "bottom"]);
const alignOptions = compositionOptions("align", ["left", "center", "right"]);
const measureOptions = compositionOptions("measure", ["full", "two_thirds", "half"]);

const treatmentOptions = compositionOptions("bg_treatment", ["none", "blur", "mono", "duotone", "grain"]);
const veilOptions = compositionOptions("bg_veil", ["flat", "bottom", "top"]);
const frameOptions = compositionOptions("media_frame", ["none", "line", "shadow"]);
const bandOptions = compositionOptions("band", ["none", "left", "bottom", "edge"]);
const titleScaleOptions = compositionOptions("title_scale", ["normal", "quiet", "loud"]);
const groundOptions = compositionOptions("ground", ["normal", "inverted", "accent"]);
const slideTransitionOptions = computed(() => [
    { value: "", label: t("backend.studio.decks.transition_from_deck") },
    ...["none", "fade", "slide"].map((value) => ({
        value,
        label: t(`backend.studio.decks.transitions.${value}`),
    })),
]);

const shapeOptions = computed(() => [
    { value: "soft", label: t("backend.studio.decks.media_shape_soft") },
    { value: "round", label: t("backend.studio.decks.media_shape_round") },
    { value: "arch", label: t("backend.studio.decks.media_shape_arch") },
    { value: "circle", label: t("backend.studio.decks.media_shape_circle") },
]);

const focus = () => {
    const stored = selected.value?.content.mediaFocus;

    if (!stored) return { x: null, y: null };

    const [x, y] = stored.split(/\s+/).map((part) => parseInt(part, 10) / 100);

    return { x, y };
};

function writeFocus(axis, value) {
    const current = focus();
    const next = { ...current, [axis]: value };

    // Half a position is not a position: clearing one axis clears both, and
    // the picture falls back to the point the document itself carries.
    if (next.x === null || next.y === null) {
        writeSlot("mediaFocus", null);

        return;
    }

    writeSlot(
        "mediaFocus",
        `${Math.round(next.x * 100)}% ${Math.round(next.y * 100)}%`,
    );
}

/**
 * The backdrop, as the picker speaks it.
 *
 * Same arrangement as the picture slot above: the model stores an id, the
 * picker wants `{id, url}`, and the address is written alongside so the preview
 * redraws without a round trip. `bgMediaUrl` is not one of the slots, so the
 * manager drops it on save.
 */
const backdrop = () => ({
    id: selected.value?.content.bgMediaId ?? null,
    url: selected.value?.content.bgMediaUrl ?? null,
});

function writeBackdrop(value) {
    if (!selected.value) return;

    writeSlot("bgMediaId", value?.id ?? null);
    writeSlot("bgMediaUrl", value?.url ?? null);

    // A backdrop with no veil is a slide whose text sits on a photograph. Forty
    // per cent is the point where a title stays readable over most pictures;
    // the slider is right there for the ones where it does not.
    if (value?.id && selected.value.content.bgDim == null) writeSlot("bgDim", 40);
}

/**
 * A list slot is an array in the model and one line per row in the form.
 *
 * The same shape for bullets, cards, steps and table rows, because they are the
 * same gesture: type a line, press return, type the next. What differs between
 * them is what a line means, and that is what the placeholder is for.
 */
const linesText = (slot) => (selected.value?.content[slot] ?? []).join("\n");

function writeLines(slot, value) {
    writeSlot(
        slot,
        value.split("\n").map((line) => line.trim()).filter(Boolean),
    );
}

/** Which side the picture sits on, in the layout that has one. */
const sideOptions = computed(() => [
    { value: "left", label: t("backend.studio.decks.side_left") },
    { value: "right", label: t("backend.studio.decks.side_right") },
]);

const chartOptions = computed(() =>
    ["bar", "line", "doughnut"].map((kind) => ({
        value: kind,
        label: t(`backend.studio.decks.chart_types.${kind}`),
    })),
);

/**
 * The last write out.
 *
 * `beforeunload` rather than only `onBeforeUnmount`: closing the tab never
 * unmounts anything, and that is exactly the moment somebody has just typed a
 * sentence they expect to find again.
 */
function onLeave() {
    flushCurrent();
}

onMounted(() => window.addEventListener("beforeunload", onLeave));
onBeforeUnmount(() => {
    window.removeEventListener("beforeunload", onLeave);
    clearTimeout(autosave);
    flushCurrent();
});
</script>

<template>
    <div class="aurora-stack">
        <!-- Présenter, et le reste derrière un bouton : la page a déjà une
             colonne de slides à gauche, elle n'a pas besoin d'une rangée de
             cinq boutons en haut. La barre de tous les écrans : le retour à
             gauche, les commandes à droite (02/10/2026). -->
        <AppPageBar :back-href="backPath" :back-label="backPath ? t('shared.common.back') : null">
            <AppPageActions
                :actions="deckActions"
                :label="deck.title ?? ''"
                icon-only-on-phone
            />
            <AppButton
                :disabled="!slides.length"
                :label="t('backend.studio.decks.present')"
                icon-only-on-phone
                v-on:click="present"
            >
                <Play class="h-4 w-4" :stroke-width="2" />
            </AppButton>
        </AppPageBar>

        <!-- Le mode d'emploi de l'écran, replié au départ : ouvert, il
             pousserait la colonne des slides et la slide sous la ligne de
             flottaison, et c'est elles qu'on vient modifier. -->
        <AppGuide :title="t('backend.studio.decks.editor_guide.title')" storage-key="deck-editor" :open="false">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 4" :key="step">{{ t(`backend.studio.decks.editor_guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>

        <div class="flex flex-col gap-4" :class="isFree ? '' : 'xl:flex-row xl:items-start'">
            <!-- Les slides, dans l'ordre où elles seront montrées. -->
            <aside class="w-full shrink-0" :class="isFree ? '' : 'xl:w-64'">
                <div class="mb-2 flex items-center justify-between">
                    <h2 class="m-0 text-xs font-semibold uppercase tracking-wide text-muted">
                        {{ t("backend.studio.decks.slides") }}
                    </h2>
                    <span class="text-xs tabular-nums text-muted">{{ slides.length }}</span>
                </div>

                <!-- La poignée plutôt que la vignette entière : la vignette est
                     un bouton qui sélectionne la slide, et un cliquer-glisser
                     qui commence sur un bouton devient une sélection ratée une
                     fois sur deux. -->
                <VueDraggable
                    :model-value="slides"
                    handle=".slide-drag-handle"
                    :animation="150"
                    :disabled="!editable"
                    class="flex snap-x gap-2 overflow-x-auto pb-1"
                    :class="isFree ? '' : 'xl:snap-none xl:flex-col xl:overflow-visible xl:pb-0'"
                    v-on:update:model-value="reorder"
                >
                    <div
                        v-for="(slide, at) in slides"
                        v-show="!isHidden(at)"
                        :key="slide.id"
                        class="group relative w-40 shrink-0 snap-start rounded-lg border p-1 transition-colors sm:w-48"
                        :class="[
                            isFree ? '' : 'xl:w-auto',
                            slide.id === selectedId
                                ? 'border-accent bg-accent-600/10'
                                : 'border-line hover:border-line-strong',
                            foldable(slide) ? 'border-dashed' : '',
                        ]"
                    >
                        <!-- Le chevron sur la vignette d'intercalaire : c'est
                             lui le chapitre, on ne stocke rien de plus. -->
                        <button
                            v-if="foldable(slide)"
                            type="button"
                            class="absolute top-1 left-1 z-10 flex cursor-pointer items-center gap-1 rounded bg-surface/80 px-1 py-0.5 text-[0.65rem] text-muted backdrop-blur"
                            :aria-expanded="!isFolded(slide.id)"
                            :title="isFolded(slide.id)
                                ? t('backend.studio.decks.unfold_chapter')
                                : t('backend.studio.decks.fold_chapter')"
                            v-on:click.stop="toggleChapter(slide.id)"
                        >
                            <ChevronDown v-if="!isFolded(slide.id)" class="h-3 w-3" :stroke-width="2" />
                            <ChevronRight v-else class="h-3 w-3" :stroke-width="2" />
                            <span class="tabular-nums">{{ sizes[slide.id] }}</span>
                        </button>
                        <!-- `flex flex-col` plutôt que `block` : le contenu d'un
                         `<button>` se comporte comme une boîte qui étire ses
                         enfants, ce qui écrasait le rapport 16/9 de la
                         vignette et la rendait carrée. -->
                        <button
                            type="button"
                            class="flex w-full cursor-pointer flex-col items-stretch border-0 bg-transparent p-0 text-left"
                            :aria-current="slide.id === selectedId ? 'true' : undefined"
                            v-on:click="select(slide.id)"
                        >
                            <!-- Décalé quand le chevron est là : la pastille
                                 est posée sur ce coin, et « INTERCALAIRE »
                                 passait dessous. -->
                            <span
                                class="mb-1 block px-1 text-[0.65rem] uppercase tracking-wide text-muted"
                                :class="foldable(slide) ? 'pl-9' : ''"
                            >
                                {{ at + 1 }}. {{ t(`backend.studio.decks.layouts.${slide.layout}`) }}
                            </span>
                            <!-- Dans une simple boîte de bloc : élément flex, la
                             vignette voyait sa hauteur décidée par son contenu
                             et le rapport 16/9 restait lettre morte. -->
                            <span class="block w-full">
                                <SlideFrame
                                    :slide="slide"
                                    :appearance="appearance"
                                    :index="at + 1"
                                    compact
                                />
                            </span>
                        </button>

                        <div
                            v-if="editable"
                            class="absolute top-1 right-1 flex gap-0.5 transition-opacity focus-within:opacity-100 sm:opacity-0 sm:group-hover:opacity-100"
                        >
                            <!-- Les flèches restent à côté de la poignée : le
                                 glisser demande une souris et une main, elles
                                 non, et c'est le seul chemin au clavier vers
                                 un changement d'ordre. -->
                            <AppIconButton
                                :disabled="at === 0"
                                :title="t('backend.studio.decks.move_up')"
                                v-on:click="move(slide, -1)"
                            >
                                <ArrowUp class="h-3 w-3" :stroke-width="2" />
                            </AppIconButton>
                            <AppIconButton
                                :disabled="at === slides.length - 1"
                                :title="t('backend.studio.decks.move_down')"
                                v-on:click="move(slide, 1)"
                            >
                                <ArrowDown class="h-3 w-3" :stroke-width="2" />
                            </AppIconButton>
                            <AppIconButton
                                :title="t('backend.studio.decks.duplicate_slide')"
                                v-on:click="duplicateSlide(slide)"
                            >
                                <CopyPlus class="h-3 w-3" :stroke-width="2" />
                            </AppIconButton>
                            <AppIconButton
                                :title="t('backend.studio.decks.delete_slide')"
                                v-on:click="pendingDelete = slide"
                            >
                                <Trash2 class="h-3 w-3" :stroke-width="2" />
                            </AppIconButton>
                        </div>

                        <span
                            v-if="editable"
                            class="slide-drag-handle absolute bottom-1 right-1 cursor-grab rounded p-0.5 text-muted opacity-0 transition-opacity group-hover:opacity-100 active:cursor-grabbing"
                            :title="t('backend.studio.decks.drag_hint')"
                        >
                            <GripVertical class="h-3.5 w-3.5" :stroke-width="2" />
                        </span>
                    </div>
                </VueDraggable>

                <div v-if="editable" class="mt-3 space-y-1">
                    <!-- Repliée sous 1280 px : vingt gabarits les uns sous les
                         autres poussaient la slide hors de l'écran d'un
                         téléphone. -->
                    <button
                        type="button"
                        class="flex w-full cursor-pointer items-center justify-between rounded-md border-0 bg-transparent px-1 py-1 text-xs text-muted"
                        :class="isFree ? '' : 'xl:hidden'"
                        :aria-expanded="addingOpen"
                        v-on:click="addingOpen = !addingOpen"
                    >
                        {{ t("backend.studio.decks.add_slide") }}
                        <ChevronDown class="h-3.5 w-3.5 transition-transform" :class="addingOpen ? 'rotate-180' : ''" :stroke-width="2" />
                    </button>
                    <p class="m-0 hidden px-1 text-xs text-muted" :class="isFree ? '' : 'xl:block'">{{ t("backend.studio.decks.add_slide") }}</p>
                    <div class="grid-cols-2 gap-1 sm:grid-cols-4" :class="[addingOpen ? 'grid' : 'hidden', isFree ? 'lg:grid-cols-6' : 'xl:grid xl:grid-cols-2']">
                        <AppButton
                            v-for="layout in layouts"
                            :key="layout.value"
                            variant="ghost"
                            size="sm"
                            v-on:click="addSlide(layout.value)"
                        >
                            <Plus class="h-3 w-3" :stroke-width="2" />
                            {{ t(layout.labelKey) }}
                        </AppButton>
                    </div>
                </div>
            </aside>

            <section class="min-w-0 flex-1 space-y-4">
                <AppNoData
                    v-if="!selected"
                    :icon="Presentation"
                    :message="t('backend.studio.decks.no_slide_title')"
                    :hint="t('backend.studio.decks.no_slide_description')"
                />

                <template v-else>
                    <!-- Une slide libre : la barre qui y ajoute, la slide qu'on
                         prend en main, et à côté ce qui règle ce qui est pris. -->
                    <div v-if="isFree" class="flex flex-col gap-4 xl:flex-row xl:items-start">
                        <div class="min-w-0 flex-1 space-y-2">
                            <FreeInsertBar
                                v-if="editable"
                                :shapes="freeOptions.shapes ?? []"
                                v-on:insert="insertElement"
                            />
                            <FreeCanvas
                                :slide="selected"
                                :appearance="appearance"
                                :index="playFrom + 1"
                                :editor="freeEditor"
                                :editable="editable"
                                :keyboard="!playing && !sharing && !appearanceOpen && !pendingDelete"
                            />
                            <div class="flex flex-wrap items-center gap-2">
                                <AppIconButton
                                    :disabled="!freeEditor.canUndo.value"
                                    :title="t('backend.studio.decks.free.undo')"
                                    v-on:click="freeEditor.undo()"
                                >
                                    <Undo2 class="h-4 w-4" :stroke-width="2" />
                                </AppIconButton>
                                <AppIconButton
                                    :disabled="!freeEditor.canRedo.value"
                                    :title="t('backend.studio.decks.free.redo')"
                                    v-on:click="freeEditor.redo()"
                                >
                                    <Redo2 class="h-4 w-4" :stroke-width="2" />
                                </AppIconButton>
                                <span class="text-xs text-muted pointer-coarse:hidden">{{ t("backend.studio.decks.free.shortcuts") }}</span>
                                <span class="hidden text-xs text-muted pointer-coarse:inline">{{ t("backend.studio.decks.free.shortcuts_touch") }}</span>
                            </div>
                        </div>
                        <aside class="aurora-card w-full shrink-0 space-y-5 p-3 xl:sticky xl:top-4 xl:max-h-[calc(100vh-2rem)] xl:w-72 xl:overflow-y-auto 2xl:w-80">
                            <FreeInspector
                                :editor="freeEditor"
                                :slide="selected"
                                :appearance="appearance"
                                :options="freeOptions"
                                :editable="editable"
                                :can-upload-font="canUploadFont"
                                :font-uploading="fontUploading"
                                v-on:upload-font="uploadFont"
                            />
                            <div class="border-t border-line pt-3">
                                <FreeLayers :editor="freeEditor" :editable="editable" />
                            </div>
                        </aside>
                    </div>

                    <div v-else ref="previewFrame" class="mx-auto max-w-3xl space-y-2">
                        <SlideFrame
                            :slide="selected"
                            :appearance="appearance"
                            :index="playFrom + 1"
                        />
                        <div v-if="editable" class="flex justify-end">
                            <AppButton variant="ghost" size="sm" v-on:click="convertToFree">
                                <WandSparkles class="h-4 w-4" :stroke-width="2" />
                                {{ t("backend.studio.decks.free.convert") }}
                            </AppButton>
                        </div>
                    </div>

                    <div class="aurora-card mx-auto max-w-3xl space-y-4 p-2 sm:p-4">
                        <div class="flex items-center justify-between gap-3">
                            <AppSelect
                                v-if="!isFree"
                                :model-value="selected.layout"
                                :options="layoutOptions"
                                :label="t('backend.studio.decks.layout')"
                                :disabled="!editable"
                                class="flex-1"
                                v-on:update:model-value="pickLayout"
                            />
                            <p v-else class="m-0 flex-1 text-sm font-medium text-primary">
                                {{ freeLabel }}
                            </p>
                            <span class="shrink-0 self-end pb-2 text-xs text-muted">
                                {{ saving
                                    ? t("backend.studio.decks.saving")
                                    : dirty
                                        ? t("backend.studio.decks.unsaved")
                                        : t("backend.studio.decks.saved") }}
                            </span>
                        </div>

                        <template v-for="slot in isFree ? [] : slots" :key="slot">
                            <AppTextarea
                                v-if="listSlots.includes(slot)"
                                :model-value="linesText(slot)"
                                :label="labelFor(slot)"
                                :placeholder="t(`backend.studio.decks.line_placeholders.${slot}`)"
                                :rows="5"
                                :disabled="!editable"
                                v-on:update:model-value="(value) => writeLines(slot, value)"
                            />
                            <AppSelect
                                v-else-if="slot === 'mediaFit'"
                                :model-value="selected.content.mediaFit ?? 'contain'"
                                :options="fitOptions"
                                :label="labelFor(slot)"
                                :disabled="!editable"
                                v-on:update:model-value="(value) => writeSlot('mediaFit', value)"
                            />
                            <div v-else-if="slot === 'mediaIds'" class="flex flex-col gap-2">
                                <span class="text-xs uppercase tracking-wide text-muted">
                                    {{ labelFor(slot) }}
                                </span>
                                <div class="grid gap-2 sm:grid-cols-2">
                                    <AppImagePickerField
                                        v-for="row in pictureRows"
                                        :key="row.at"
                                        :model-value="row.id ? { id: row.id, url: row.url } : null"
                                        :label="t('backend.studio.decks.picture_rank', { rank: row.at + 1 })"
                                        :size="90"
                                        v-on:update:model-value="(value) => writePictureAt(row.at, value)"
                                    />
                                </div>
                                <p class="m-0 text-xs text-muted">
                                    {{ t("backend.studio.decks.media_ids_hint") }}
                                </p>
                            </div>
                            <AppSelect
                                v-else-if="slot === 'mediaFrame'"
                                :model-value="selected.content.mediaFrame ?? 'none'"
                                :options="frameOptions"
                                :label="labelFor(slot)"
                                :disabled="!editable"
                                v-on:update:model-value="(value) => writeSlot('mediaFrame', value)"
                            />
                            <AppToggle
                                v-else-if="slot === 'mediaBleed'"
                                :model-value="selected.content.mediaBleed === true"
                                :label="labelFor(slot)"
                                :hint="t('backend.studio.decks.media_bleed_hint')"
                                :disabled="!editable"
                                v-on:update:model-value="(value) => writeSlot('mediaBleed', value)"
                            />
                            <AppToggle
                                v-else-if="slot === 'captionOver'"
                                :model-value="selected.content.captionOver === true"
                                :label="labelFor(slot)"
                                :disabled="!editable"
                                v-on:update:model-value="(value) => writeSlot('captionOver', value)"
                            />
                            <AppSelect
                                v-else-if="slot === 'mediaShape'"
                                :model-value="selected.content.mediaShape ?? 'soft'"
                                :options="shapeOptions"
                                :label="labelFor(slot)"
                                :disabled="!editable"
                                v-on:update:model-value="(value) => writeSlot('mediaShape', value)"
                            />
                            <!-- Viser ne se fait qu'une fois l'image choisie :
                                 un cadre de visée vide n'a rien à montrer et
                                 rien à recevoir. -->
                            <AppFocalPointField
                                v-else-if="slot === 'mediaFocus' && selected.content.mediaUrl"
                                :src="selected.content.mediaUrl"
                                :label="labelFor(slot)"
                                :hint="t('backend.studio.decks.media_focus_hint')"
                                :x="focus().x"
                                :y="focus().y"
                                :inherited="selected.content.mediaFocusDefault ?? '50% 50%'"
                                :fit-class="selected.content.mediaFit === 'cover' ? 'object-cover' : 'object-contain'"
                                v-on:update:x="(value) => writeFocus('x', value)"
                                v-on:update:y="(value) => writeFocus('y', value)"
                            />
                            <AppSelect
                                v-else-if="slot === 'chartType'"
                                :model-value="selected.content.chartType ?? 'bar'"
                                :options="chartOptions"
                                :label="labelFor(slot)"
                                :disabled="!editable"
                                v-on:update:model-value="(value) => writeSlot('chartType', value)"
                            />
                            <AppSelect
                                v-else-if="slot === 'side'"
                                :model-value="selected.content.side ?? 'left'"
                                :options="sideOptions"
                                :label="labelFor(slot)"
                                :disabled="!editable"
                                v-on:update:model-value="(value) => writeSlot('side', value)"
                            />
                            <AppTextarea
                                v-else-if="['left', 'right', 'quote', 'text'].includes(slot)"
                                :model-value="selected.content[slot] ?? ''"
                                :label="labelFor(slot)"
                                :placeholder="t('backend.studio.decks.prose_placeholder')"
                                :rows="3"
                                :disabled="!editable"
                                v-on:update:model-value="(value) => writeSlot(slot, value)"
                            />
                            <AppImagePickerField
                                v-else-if="slot === 'mediaId'"
                                :model-value="picture()"
                                :label="labelFor(slot)"
                                :hint="t('backend.studio.decks.image_hint')"
                                :size="160"
                                v-on:update:model-value="writePicture"
                            />
                            <AppInput
                                v-else
                                :model-value="selected.content[slot] ?? ''"
                                :label="labelFor(slot)"
                                :placeholder="t('backend.studio.decks.prose_placeholder')"
                                :disabled="!editable"
                                v-on:update:model-value="(value) => writeSlot(slot, value)"
                            />
                        </template>

                        <div class="flex flex-col gap-4 border-t border-line pt-4">
                            <p class="m-0 text-xs font-semibold uppercase tracking-wide text-muted">
                                {{ t("backend.studio.decks.common_slots") }}
                            </p>

                            <AppInput
                                v-if="commonSlots.includes('kicker') && !isFree"
                                :model-value="selected.content.kicker ?? ''"
                                :label="labelFor('kicker')"
                                :placeholder="t('backend.studio.decks.kicker_placeholder')"
                                :disabled="!editable"
                                v-on:update:model-value="(value) => writeSlot('kicker', value)"
                            />

                            <div v-if="commonSlots.includes('anchor')" class="grid gap-3 sm:grid-cols-3">
                                <AppSelect
                                    v-if="!isFree"
                                    :model-value="selected.content.anchor ?? 'center'"
                                    :options="anchorOptions"
                                    :label="labelFor('anchor')"
                                    :disabled="!editable"
                                    v-on:update:model-value="(value) => writeSlot('anchor', value)"
                                />
                                <AppSelect
                                    v-if="!isFree"
                                    :model-value="selected.content.align ?? 'left'"
                                    :options="alignOptions"
                                    :label="labelFor('align')"
                                    :disabled="!editable"
                                    v-on:update:model-value="(value) => writeSlot('align', value)"
                                />
                                <AppSelect
                                    v-if="!isFree"
                                    :model-value="selected.content.titleScale ?? 'normal'"
                                    :options="titleScaleOptions"
                                    :label="labelFor('titleScale')"
                                    :disabled="!editable"
                                    v-on:update:model-value="(value) => writeSlot('titleScale', value)"
                                />
                                <AppSelect
                                    :model-value="selected.content.band ?? 'none'"
                                    :options="bandOptions"
                                    :label="labelFor('band')"
                                    :disabled="!editable"
                                    v-on:update:model-value="(value) => writeSlot('band', value)"
                                />
                                <AppSelect
                                    v-if="!isFree"
                                    :model-value="selected.content.measure ?? 'full'"
                                    :options="measureOptions"
                                    :label="labelFor('measure')"
                                    :disabled="!editable"
                                    v-on:update:model-value="(value) => writeSlot('measure', value)"
                                />
                            </div>

                            <AppSelect
                                v-if="commonSlots.includes('ground')"
                                :model-value="selected.content.ground ?? 'normal'"
                                :options="groundOptions"
                                :label="labelFor('ground')"
                                :hint="t('backend.studio.decks.ground_hint')"
                                :disabled="!editable"
                                v-on:update:model-value="(value) => writeSlot('ground', value)"
                            />

                            <AppImagePickerField
                                v-if="commonSlots.includes('bgMediaId')"
                                :model-value="backdrop()"
                                :label="labelFor('bgMediaId')"
                                :hint="t('backend.studio.decks.backdrop_hint')"
                                :size="120"
                                v-on:update:model-value="writeBackdrop"
                            />

                            <!-- Le curseur n'a de sens qu'avec une image
                                 derrière : voilé à 40 %, un fond qui n'existe
                                 pas ne change rien et le réglage n'explique
                                 rien. -->
                            <div v-if="selected.content.bgMediaUrl" class="flex flex-col gap-1.5">
                                <span class="text-xs uppercase tracking-wide text-muted">
                                    {{ t("backend.studio.decks.backdrop_dim") }}
                                    <span class="tabular-nums">{{ selected.content.bgDim ?? 40 }} %</span>
                                </span>
                                <AppRange
                                    :model-value="selected.content.bgDim ?? 40"
                                    :min="0"
                                    :max="90"
                                    :step="5"
                                    :disabled="!editable"
                                    v-on:update:model-value="(value) => writeSlot('bgDim', value)"
                                />
                                <p class="m-0 text-xs text-muted">
                                    {{ t("backend.studio.decks.backdrop_dim_hint") }}
                                </p>

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <AppSelect
                                        :model-value="selected.content.bgTreatment ?? 'none'"
                                        :options="treatmentOptions"
                                        :label="labelFor('bgTreatment')"
                                        :disabled="!editable"
                                        v-on:update:model-value="(value) => writeSlot('bgTreatment', value)"
                                    />
                                    <AppSelect
                                        :model-value="selected.content.bgVeil ?? 'flat'"
                                        :options="veilOptions"
                                        :label="labelFor('bgVeil')"
                                        :disabled="!editable"
                                        v-on:update:model-value="(value) => writeSlot('bgVeil', value)"
                                    />
                                </div>
                            </div>

                            <AppSelect
                                v-if="commonSlots.includes('transition')"
                                :model-value="selected.content.transition ?? ''"
                                :options="slideTransitionOptions"
                                :label="labelFor('transition')"
                                :hint="t('backend.studio.decks.slide_transition_hint')"
                                :disabled="!editable"
                                v-on:update:model-value="(value) => writeSlot('transition', value || null)"
                            />

                            <AppToggle
                                v-if="commonSlots.includes('reveal') && !isFree"
                                :model-value="selected.content.reveal === true"
                                :label="labelFor('reveal')"
                                :hint="t('backend.studio.decks.reveal_hint')"
                                :disabled="!editable"
                                v-on:update:model-value="(value) => writeSlot('reveal', value)"
                            />

                            <AppToggle
                                v-if="commonSlots.includes('drift') && selected.content.bgMediaUrl"
                                :model-value="selected.content.drift === true"
                                :label="labelFor('drift')"
                                :hint="t('backend.studio.decks.drift_hint')"
                                :disabled="!editable"
                                v-on:update:model-value="(value) => writeSlot('drift', value)"
                            />

                            <AppToggle
                                v-if="commonSlots.includes('vignette')"
                                :model-value="selected.content.vignette === true"
                                :label="labelFor('vignette')"
                                :hint="t('backend.studio.decks.vignette_hint')"
                                :disabled="!editable"
                                v-on:update:model-value="(value) => writeSlot('vignette', value)"
                            />
                        </div>

                        <template v-if="!isFree">
                            <p class="m-0 text-xs text-muted">
                                {{ t("backend.studio.decks.emphasis_hint") }}
                            </p>

                            <p class="m-0 text-xs text-muted">
                                {{ t("backend.studio.decks.icons_hint") }}
                            </p>
                        </template>

                        <AppTextarea
                            :model-value="selected.speakerNotes ?? ''"
                            :label="t('backend.studio.decks.speaker_notes')"
                            :placeholder="t('backend.studio.decks.speaker_notes_placeholder')"
                            :hint="t('backend.studio.decks.speaker_notes_hint')"
                            :rows="3"
                            :disabled="!editable"
                            v-on:update:model-value="writeNotes"
                        />
                    </div>
                </template>
            </section>

            <AppModal
                :show="!!pendingDelete"
                max-width="sm"
                :closeable="false"
                :title="t('backend.studio.decks.delete_slide')"
                :icon="Trash2"
                v-on:close="pendingDelete = null"
            >
                <p class="text-sm text-primary">{{ t("backend.studio.decks.delete_slide_confirm") }}</p>
                <template #footer>
                    <AppModalFooter>
                        <AppButton variant="ghost" size="md" v-on:click="pendingDelete = null">
                            <X class="h-3.5 w-3.5" :stroke-width="2" />
                            {{ t("shared.common.cancel") }}
                        </AppButton>
                        <AppButton variant="danger" size="md" v-on:click="confirmDeleteSlide">
                            <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                            {{ t("shared.common.delete") }}
                        </AppButton>
                    </AppModalFooter>
                </template>
            </AppModal>
        </div>

        <AppModal
            :show="sharing"
            max-width="lg"
            :title="t('backend.studio.decks.share')"
            :icon="Share2"
            v-on:close="sharing = false"
        >
            <div class="space-y-4">
                <p class="m-0 text-sm text-secondary">
                    {{ t("backend.studio.decks.share_intro") }}
                </p>

                <!-- L'avertissement avant le formulaire : il change ce qu'on
                     s'apprête à envoyer, pas ce qu'on vient d'envoyer. -->
                <div
                    v-if="withheld.length"
                    class="flex flex-col gap-1 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3"
                    role="status"
                >
                    <p class="m-0 text-sm font-medium text-amber-300">
                        {{ t("backend.studio.decks.withheld_title", withheld.length) }}
                    </p>
                    <p class="m-0 text-xs text-secondary">
                        {{ t("backend.studio.decks.withheld_hint") }}
                    </p>
                    <p class="m-0 truncate text-xs text-muted">
                        {{ withheld.map((picture) => picture.name).join(", ") }}
                    </p>
                </div>

                <div class="flex flex-wrap items-end gap-2">
                    <AppInput
                        v-model="newLabel"
                        class="min-w-48 flex-1"
                        :label="t('backend.studio.decks.share_label')"
                        :placeholder="t('backend.studio.decks.share_label_placeholder')"
                    />
                    <AppSelect
                        v-model="expiresInDays"
                        class="w-44"
                        :label="t('backend.studio.decks.share_expiry')"
                        :options="[
                            { value: '', label: t('backend.studio.decks.share_no_expiry') },
                            { value: '7', label: t('backend.studio.decks.share_days', { count: 7 }) },
                            { value: '30', label: t('backend.studio.decks.share_days', { count: 30 }) },
                            { value: '90', label: t('backend.studio.decks.share_days', { count: 90 }) },
                        ]"
                    />
                    <AppButton variant="primary" :loading="creating" v-on:click="createLink">
                        <Plus class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("backend.studio.decks.share_create") }}
                    </AppButton>
                </div>

                <AppInput
                    v-model="newPassword"
                    type="password"
                    :label="t('backend.studio.decks.share_password')"
                    :placeholder="t('backend.studio.decks.share_password_placeholder')"
                    :hint="t('backend.studio.decks.share_password_hint')"
                />

                <p v-if="!links.length" class="m-0 text-sm text-muted">
                    {{ t("backend.studio.decks.share_none") }}
                </p>

                <ul v-else class="m-0 flex list-none flex-col gap-2 p-0">
                    <li
                        v-for="link in links"
                        :key="link.id"
                        class="rounded-lg border border-line p-3"
                        :class="isLive(link) ? '' : 'opacity-60'"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="min-w-0 text-sm font-medium text-primary">
                                {{ link.label || t("backend.studio.decks.share_untitled") }}
                            </span>
                            <span class="flex shrink-0 gap-1">
                                <AppIconButton
                                    :title="t('backend.studio.decks.share_copy')"
                                    v-on:click="copy(link)"
                                >
                                    <Copy class="h-3.5 w-3.5" :stroke-width="2" />
                                </AppIconButton>
                                <AppIconButton
                                    v-if="isDeletable(link)"
                                    :title="t('backend.studio.decks.share_delete')"
                                    v-on:click="remove(link)"
                                >
                                    <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                                </AppIconButton>
                                <AppIconButton
                                    v-else-if="isLive(link)"
                                    :title="t('backend.studio.decks.share_revoke')"
                                    v-on:click="revoke(link)"
                                >
                                    <X class="h-3.5 w-3.5" :stroke-width="2" />
                                </AppIconButton>
                            </span>
                        </div>

                        <!-- `select-all` : un clic ou un triple clic prend l'adresse entière, et elle seule.
                             Sans cela, la sélection d'une ligne emportait aussi la ligne du dessous
                             (« Sans expiration · Jamais ouvert »), et l'adresse collée donnait un 404. -->
                        <p class="m-0 mt-1 select-all truncate font-mono text-xs text-muted">
                            {{ copiedId === link.id ? t("backend.studio.decks.share_copied") : link.url }}
                        </p>

                        <p class="m-0 mt-1 text-xs text-muted">
                            <span v-if="link.revokedAt">{{ t("backend.studio.decks.share_revoked") }}</span>
                            <span v-else-if="link.expiresAt">{{ t("backend.studio.decks.share_expires_on", { date: d(new Date(link.expiresAt), "short") }) }}</span>
                            <span v-else>{{ t("backend.studio.decks.share_no_expiry") }}</span>
                            <span v-if="link.locked"> · {{ t("backend.studio.decks.share_locked") }}</span>
                            <span v-if="link.lastUsedAt">
                                ·
                                {{ link.openCount > 1
                                    ? t("backend.studio.decks.share_opened", { count: link.openCount })
                                    : t("backend.studio.decks.share_opened_once") }}
                                · {{ t("backend.studio.decks.share_last_used", { date: d(new Date(link.lastUsedAt), "short") }) }}
                            </span>
                            <span v-else> · {{ t("backend.studio.decks.share_never_opened") }}</span>
                        </p>
                    </li>
                </ul>
            </div>
        </AppModal>

        <DeckPlayer
            v-if="playing"
            :slides="slides"
            :appearance="appearance"
            :channel="deck.id"
            :start-at="playFrom"
            v-on:close="playing = false"
        />

        <DeckAppearancePanel
            :show="appearanceOpen"
            :themes="themes"
            :font-pairs="fontPairs"
            :logo-placements="logoPlacements"
            :looks="looks"
            :gradients="gradients"
            :patterns="patterns"
            :margins="margins"
            :title-cases="titleCases"
            :bullet-shapes="bulletShapes"
            :transitions="transitions"
            :sample="slides[0] ?? null"
            :theme="theme"
            :overrides="style"
            :logo="logo"
            :inherited="inherited"
            :preview="preview"
            :is-overridden="isOverridden"
            :carries-overrides="carriesOverrides"
            :saving="savingAppearance"
            v-on:close="appearanceOpen = false"
            v-on:save="saveAppearance"
            v-on:write="writeStyle"
            v-on:update:theme="(value) => (theme = value)"
            v-on:update:logo="writeLogo"
            v-on:reset-colours="resetColours"
            v-on:apply-look="applyLook"
        />
    </div>
</template>
