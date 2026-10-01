<script setup>
/**
 * Everything about what is selected, and about the slide when nothing is.
 *
 * **What is shown follows what is picked.** A text box shows its type, a
 * picture its crop and adjustments, a film how it plays; what every element
 * shares - where it is, how it turns, how it comes in, where it links - is
 * below, in the same place each time. Several elements together show only
 * what can be set on all of them at once.
 *
 * **A field is one step of the history, however many times it is nudged.**
 * Every write passes the field's name as `coalesce`, so a slider dragged
 * across its range is undone in one press, like the drag it is.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import {
    AlignCenterHorizontal,
    AlignCenterVertical,
    AlignEndHorizontal,
    AlignEndVertical,
    AlignHorizontalDistributeCenter,
    AlignStartHorizontal,
    AlignStartVertical,
    AlignVerticalDistributeCenter,
    ArrowDownToLine,
    ArrowUpToLine,
    BringToFront,
    CopyPlus,
    FlipHorizontal2,
    FlipVertical2,
    Group,
    Lock,
    LockOpen,
    SendToBack,
    Trash2,
    Ungroup,
} from "lucide-vue-next";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import AppRange from "@/shared/components/form/toggle/AppRange.vue";
import AppFocalPointField from "@/shared/components/form/file/AppFocalPointField.vue";
import { openDocumentPicker } from "@/shared/utils/documentPicker.js";
import FreeColourField from "./FreeColourField.vue";
import FreeFontField from "./FreeFontField.vue";
import FreeNumberField from "./FreeNumberField.vue";
import FreePaintField from "./FreePaintField.vue";
import { FREE_ICONS } from "./icons.js";
import { boundsOf, embedPreview } from "./model.js";
import { readability } from "../colour.js";

const props = defineProps({
    editor: { type: Object, required: true },
    slide: { type: Object, required: true },
    appearance: { type: Object, default: null },
    options: { type: Object, default: () => ({}) },
    editable: { type: Boolean, default: true },
    canUploadFont: { type: Boolean, default: false },
    fontUploading: { type: Boolean, default: false },
});

const emit = defineEmits(["upload-font"]);

const { t } = useI18n();

const editor = props.editor;
const selected = computed(() => editor.selected.value);
const single = computed(() => editor.single.value);
const type = computed(() => single.value?.type ?? null);
const many = computed(() => selected.value.length > 1);
const allLocked = computed(() => selected.value.length > 0 && selected.value.every((element) => element.locked));
const grouped = computed(() => selected.value.some((element) => element.group));

/** Write one property on everything selected, as one step per field. */
const set = (key, value) => editor.patchSelected({ [key]: value }, { coalesce: key });

/** Write one property of a nested object, keeping the others. */
const setIn = (key, inner, value, fallback = {}) =>
    editor.patchSelected((element) => ({ ...element, [key]: { ...(element[key] ?? fallback), [inner]: value } }), { coalesce: `${key}.${inner}` });

const choices = (list, prefix) => (list ?? []).map((value) => ({ value, label: t(`backend.studio.decks.free.${prefix}.${value}`) }));

const enterOptions = computed(() => choices(props.options.enters, "enters"));
const maskOptions = computed(() => choices(props.options.masks, "masks"));
const shapeOptions = computed(() => choices(props.options.shapes, "shapes"));
const caseOptions = computed(() => choices(props.options.cases, "cases"));
const headOptions = computed(() => choices(props.options.heads, "heads"));
const strokeStyleOptions = computed(() => choices(props.options.strokeStyles, "stroke_styles"));
const chartOptions = computed(() => (props.options.chartTypes ?? []).map((value) => ({ value, label: t(`backend.studio.decks.chart_types.${value}`) })));
const fitOptions = computed(() => [
    { value: "cover", label: t("backend.studio.decks.media_fit_cover") },
    { value: "contain", label: t("backend.studio.decks.media_fit_contain") },
]);
const weightOptions = [100, 200, 300, 400, 500, 600, 700, 800, 900].map((value) => ({
    value: String(value),
    label: t(`backend.studio.decks.free.weights.${value}`),
}));
const iconOptions = computed(() => Object.keys(FREE_ICONS).map((name) => ({ value: name, label: name })));

const ALIGNS = [
    { key: "left", icon: AlignStartVertical },
    { key: "center", icon: AlignCenterVertical },
    { key: "right", icon: AlignEndVertical },
    { key: "top", icon: AlignStartHorizontal },
    { key: "middle", icon: AlignCenterHorizontal },
    { key: "bottom", icon: AlignEndHorizontal },
];

/** What a stroke, a radius and a fill mean depends on the element. */
const hasFill = computed(() => ["shape", "text", "table"].includes(type.value) && single.value?.shape !== "line");
const hasStroke = computed(() => !!type.value && !["chart", "embed"].includes(type.value));
const hasRadius = computed(() => ["text", "image", "video", "embed", "table"].includes(type.value) || (type.value === "shape" && single.value?.shape === "rect"));
const hasShadow = computed(() => !!type.value);
const flippable = computed(() => ["image", "video", "icon", "shape"].includes(type.value));

/** The point a picture is cropped around, as the focal field speaks it. */
const focus = computed(() => {
    const stored = single.value?.focus;

    if (!stored) return { x: null, y: null };

    const [x, y] = stored.split(/\s+/).map((part) => parseInt(part, 10) / 100);

    return { x, y };
});

function writeFocus(axis, value) {
    const next = { ...focus.value, [axis]: value };

    if (next.x === null || next.y === null) return set("focus", null);

    set("focus", `${Math.round(next.x * 100)}% ${Math.round(next.y * 100)}%`);
}

async function replacePicture() {
    const item = await openDocumentPicker({ imagesOnly: true });

    if (item) editor.patchSelected({ mediaId: item.id, mediaUrl: item.fileUrl ?? item.url ?? null });
}

async function replaceFilm() {
    const item = await openDocumentPicker({ mimePrefix: "video/" });

    if (item) editor.patchSelected({ mediaId: item.id, videoUrl: item.fileUrl ?? item.url ?? null, poster: null });
}

/** The film behind the slide, picked from the library. */
async function pickBackgroundFilm() {
    const item = await openDocumentPicker({ mimePrefix: "video/" });

    if (!item) return;

    editor.setSlot("bgVideoId", item.id);
    editor.setSlot("bgVideoUrl", item.fileUrl ?? item.url ?? null, { record: false });
}

function clearBackgroundFilm() {
    editor.setSlot("bgVideoId", null);
    editor.setSlot("bgVideoUrl", null, { record: false });
    editor.setSlot("bgVideoPoster", null, { record: false });
}

const filterValue = (name) => single.value?.filters?.[name] ?? props.options.filters?.[name]?.[2] ?? 0;

function writeFilter(name, value) {
    editor.patchSelected(
        (element) => {
            const filters = { ...(element.filters ?? {}), [name]: value };

            if (value === props.options.filters?.[name]?.[2]) delete filters[name];

            return { ...element, filters: Object.keys(filters).length ? filters : null };
        },
        { coalesce: `filter.${name}` },
    );
}

const linesOf = (key) => (single.value?.[key] ?? []).join("\n");
const writeLines = (key, value) => set(key, value.split("\n").map((line) => line.trim()).filter(Boolean));

function writeEmbed(url) {
    editor.patchSelected(embedPreview(url), { coalesce: "embed" });
}

/**
 * Whether the selected words can be read on what is under them.
 *
 * What is under them is the first painted thing found going down the stack
 * from the box: its own fill, a shape or a box drawn beneath it, then the
 * slide's paint, then the deck's ground. A picture or a film beneath answers
 * nothing - its colours are not known here - and the panel stays quiet
 * rather than guessing.
 */
const legibility = computed(() => {
    const element = single.value;

    if (!element || element.type !== "text") return null;

    const look = props.appearance ?? {};
    const hex = (value) => (["ink", "accent", "background"].includes(value) ? look[value] : value)?.slice(0, 7) ?? null;
    const solid = (fill) => (fill?.type === "solid" ? fill.color : (fill?.stops?.[0]?.color ?? null));

    let ground = solid(element.fill);

    if (!ground) {
        const list = editor.elements.value;
        const at = list.findIndex((row) => row.id === element.id);
        const centre = boundsOf(element);

        for (let below = at - 1; below >= 0 && !ground; below -= 1) {
            const other = list[below];
            const box = boundsOf(other);
            const covers = box.left <= centre.centreX && box.right >= centre.centreX && box.top <= centre.centreY && box.bottom >= centre.centreY;

            if (!covers) continue;
            if (["image", "video", "embed"].includes(other.type)) return null;

            ground = solid(other.fill);
        }
    }

    ground ??= solid(props.slide.content.fill) ?? "background";

    const result = readability(hex(element.color ?? "ink"), hex(ground));

    return result && result.level !== "good" ? result : null;
});

const shadowOn = computed(() => !!single.value?.shadow || (many.value && selected.value.every((element) => element.shadow)));

function toggleShadow(on) {
    editor.patchSelected({ shadow: on ? { x: 0, y: 12, blur: 30, color: "#00000059" } : null });
}

const strokeOf = computed(() => single.value?.stroke ?? null);

function toggleStroke(on) {
    editor.patchSelected({ stroke: on ? { color: "ink", width: 4, style: "solid" } : null });
}
</script>

<template>
    <div class="flex flex-col gap-4 text-sm">
        <!-- Rien de choisi : la slide elle-même, sa peinture et son film. -->
        <template v-if="!selected.length">
            <p class="m-0 text-xs font-semibold uppercase tracking-wide text-muted">{{ t("backend.studio.decks.free.slide") }}</p>
            <FreePaintField
                :model-value="slide.content.fill ?? null"
                :label="t('backend.studio.decks.free.slide_fill')"
                :appearance="appearance"
                v-on:update:model-value="(value) => editor.setSlot('fill', value, { coalesce: 'fill' })"
            />
            <div class="flex flex-col gap-1.5">
                <span class="text-xs font-medium uppercase tracking-wide text-secondary">{{ t("backend.studio.decks.free.slide_video") }}</span>
                <div class="flex flex-wrap gap-2">
                    <AppButton variant="ghost" size="sm" :disabled="!editable" v-on:click="pickBackgroundFilm">
                        {{ slide.content.bgVideoId ? t("backend.studio.decks.free.replace") : t("backend.studio.decks.free.choose_video") }}
                    </AppButton>
                    <AppButton v-if="slide.content.bgVideoId" variant="ghost" size="sm" v-on:click="clearBackgroundFilm">
                        {{ t("backend.studio.decks.free.remove") }}
                    </AppButton>
                </div>
                <p class="m-0 text-xs text-muted">{{ t("backend.studio.decks.free.slide_video_hint") }}</p>
            </div>
            <p class="m-0 text-xs text-muted">{{ t("backend.studio.decks.free.nothing_selected") }}</p>
        </template>

        <template v-else>
            <div class="flex flex-wrap items-center gap-0.5">
                <AppIconButton size="sm" variant="ghost" :title="t('backend.studio.decks.free.duplicate')" v-on:click="editor.duplicate()">
                    <CopyPlus class="h-4 w-4" :stroke-width="2" />
                </AppIconButton>
                <AppIconButton
                    size="sm"
                    variant="ghost"
                    :title="t('backend.studio.decks.free.delete')"
                    :disabled="allLocked"
                    v-on:click="editor.remove()"
                >
                    <Trash2 class="h-4 w-4" :stroke-width="2" />
                </AppIconButton>
                <AppIconButton size="sm" variant="ghost" :title="allLocked ? t('backend.studio.decks.free.unlock') : t('backend.studio.decks.free.lock')" v-on:click="editor.toggleLock()">
                    <LockOpen v-if="allLocked" class="h-4 w-4" :stroke-width="2" />
                    <Lock v-else class="h-4 w-4" :stroke-width="2" />
                </AppIconButton>
                <span class="mx-1 h-5 w-px bg-line" />
                <AppIconButton size="sm" variant="ghost" :title="t('backend.studio.decks.free.bring_front')" v-on:click="editor.arrange('front')">
                    <BringToFront class="h-4 w-4" :stroke-width="2" />
                </AppIconButton>
                <AppIconButton size="sm" variant="ghost" :title="t('backend.studio.decks.free.forward')" v-on:click="editor.arrange('forward')">
                    <ArrowUpToLine class="h-4 w-4" :stroke-width="2" />
                </AppIconButton>
                <AppIconButton size="sm" variant="ghost" :title="t('backend.studio.decks.free.backward')" v-on:click="editor.arrange('backward')">
                    <ArrowDownToLine class="h-4 w-4" :stroke-width="2" />
                </AppIconButton>
                <AppIconButton size="sm" variant="ghost" :title="t('backend.studio.decks.free.send_back')" v-on:click="editor.arrange('back')">
                    <SendToBack class="h-4 w-4" :stroke-width="2" />
                </AppIconButton>
                <template v-if="many || grouped">
                    <span class="mx-1 h-5 w-px bg-line" />
                    <AppIconButton
                        v-if="many"
                        size="sm"
                        variant="ghost"
                        :title="t('backend.studio.decks.free.group')"
                        v-on:click="editor.group()"
                    >
                        <Group class="h-4 w-4" :stroke-width="2" />
                    </AppIconButton>
                    <AppIconButton
                        v-if="grouped"
                        size="sm"
                        variant="ghost"
                        :title="t('backend.studio.decks.free.ungroup')"
                        v-on:click="editor.ungroup()"
                    >
                        <Ungroup class="h-4 w-4" :stroke-width="2" />
                    </AppIconButton>
                </template>
                <template v-if="flippable">
                    <span class="mx-1 h-5 w-px bg-line" />
                    <AppIconButton size="sm" variant="ghost" :title="t('backend.studio.decks.free.flip_x')" v-on:click="set('flipX', single.flipX ? null : true)">
                        <FlipHorizontal2 class="h-4 w-4" :stroke-width="2" />
                    </AppIconButton>
                    <AppIconButton size="sm" variant="ghost" :title="t('backend.studio.decks.free.flip_y')" v-on:click="set('flipY', single.flipY ? null : true)">
                        <FlipVertical2 class="h-4 w-4" :stroke-width="2" />
                    </AppIconButton>
                </template>
            </div>

            <!-- Aligner : sur la slide pour un seul élément, sur leur boîte
                 pour plusieurs. -->
            <div class="flex flex-wrap items-center gap-0.5">
                <AppIconButton
                    v-for="align in ALIGNS"
                    :key="align.key"
                    size="sm"
                    variant="ghost"
                    :title="t(`backend.studio.decks.free.align.${align.key}`)"
                    v-on:click="editor.align(align.key)"
                >
                    <component :is="align.icon" class="h-4 w-4" :stroke-width="2" />
                </AppIconButton>
                <template v-if="selected.length > 2">
                    <span class="mx-1 h-5 w-px bg-line" />
                    <AppIconButton size="sm" variant="ghost" :title="t('backend.studio.decks.free.distribute_x')" v-on:click="editor.distribute('x')">
                        <AlignHorizontalDistributeCenter class="h-4 w-4" :stroke-width="2" />
                    </AppIconButton>
                    <AppIconButton size="sm" variant="ghost" :title="t('backend.studio.decks.free.distribute_y')" v-on:click="editor.distribute('y')">
                        <AlignVerticalDistributeCenter class="h-4 w-4" :stroke-width="2" />
                    </AppIconButton>
                </template>
            </div>

            <!-- Où, combien grand, combien tourné. -->
            <div v-if="single" class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <FreeNumberField
                    :model-value="single.x"
                    label="X"
                    unit="%"
                    :step="0.5"
                    v-on:update:model-value="(value) => set('x', value)"
                />
                <FreeNumberField
                    :model-value="single.y"
                    label="Y"
                    unit="%"
                    :step="0.5"
                    v-on:update:model-value="(value) => set('y', value)"
                />
                <FreeNumberField
                    :model-value="single.w"
                    :label="t('backend.studio.decks.free.width')"
                    unit="%"
                    :min="0.2"
                    :step="0.5"
                    v-on:update:model-value="(value) => set('w', value)"
                />
                <FreeNumberField
                    :model-value="single.h"
                    :label="t('backend.studio.decks.free.height')"
                    unit="%"
                    :min="0.2"
                    :step="0.5"
                    v-on:update:model-value="(value) => set('h', value)"
                />
                <FreeNumberField
                    :model-value="single.rotate ?? 0"
                    :label="t('backend.studio.decks.free.rotation')"
                    unit="°"
                    :min="-180"
                    :max="180"
                    v-on:update:model-value="(value) => set('rotate', value || null)"
                />
            </div>

            <div class="flex items-center gap-2">
                <span class="w-24 shrink-0 text-xs text-muted">{{ t("backend.studio.decks.free.opacity") }}</span>
                <AppRange
                    :model-value="Math.round((selected[0].opacity ?? 1) * 100)"
                    :min="0"
                    :max="100"
                    :step="5"
                    v-on:update:model-value="(value) => set('opacity', value >= 100 ? null : value / 100)"
                />
                <span class="w-10 shrink-0 text-right text-xs tabular-nums text-muted">{{ Math.round((selected[0].opacity ?? 1) * 100) }} %</span>
            </div>

            <!-- Les mots. -->
            <section v-if="type === 'text'" class="flex flex-col gap-3 border-t border-line pt-3">
                <FreeFontField
                    :model-value="single.font ?? 'body'"
                    :label="t('backend.studio.decks.free.font')"
                    :appearance="appearance"
                    :can-upload="canUploadFont"
                    :uploading="fontUploading"
                    v-on:update:model-value="(value) => set('font', value)"
                    v-on:upload="(file) => emit('upload-font', file)"
                />
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    <FreeNumberField
                        :model-value="(single.size ?? 40) / 10"
                        :label="t('backend.studio.decks.free.size')"
                        :min="0.5"
                        :max="60"
                        :step="0.5"
                        v-on:update:model-value="(value) => set('size', value * 10)"
                    />
                    <AppSelect :model-value="String(single.weight ?? 400)" :options="weightOptions" :label="t('backend.studio.decks.free.weight')" v-on:update:model-value="(value) => set('weight', Number(value))" />
                    <AppSelect :model-value="single.case ?? 'none'" :options="caseOptions" :label="t('backend.studio.decks.free.case')" v-on:update:model-value="(value) => set('case', value === 'none' ? null : value)" />
                    <FreeNumberField
                        :model-value="single.spacing ?? 0"
                        :label="t('backend.studio.decks.free.spacing')"
                        :min="-100"
                        :max="500"
                        :step="5"
                        v-on:update:model-value="(value) => set('spacing', value || null)"
                    />
                    <FreeNumberField
                        :model-value="single.lineHeight ?? 1.2"
                        :label="t('backend.studio.decks.free.line_height')"
                        :min="0.6"
                        :max="4"
                        :step="0.05"
                        v-on:update:model-value="(value) => set('lineHeight', value)"
                    />
                </div>
                <div class="flex flex-wrap gap-2">
                    <div class="inline-flex rounded-lg border border-line bg-surface-2/40 p-0.5">
                        <button
                            v-for="value in options.aligns ?? []"
                            :key="value"
                            type="button"
                            class="cursor-pointer rounded-md border-0 px-2 py-1 text-xs"
                            :class="(single.align ?? 'left') === value ? 'bg-surface font-medium text-primary shadow-sm' : 'bg-transparent text-muted'"
                            v-on:click="set('align', value)"
                        >
                            {{ t(`backend.studio.decks.free.aligns.${value}`) }}
                        </button>
                    </div>
                    <div class="inline-flex rounded-lg border border-line bg-surface-2/40 p-0.5">
                        <button
                            v-for="value in options.valigns ?? []"
                            :key="value"
                            type="button"
                            class="cursor-pointer rounded-md border-0 px-2 py-1 text-xs"
                            :class="(single.valign ?? 'top') === value ? 'bg-surface font-medium text-primary shadow-sm' : 'bg-transparent text-muted'"
                            v-on:click="set('valign', value)"
                        >
                            {{ t(`backend.studio.decks.free.valigns.${value}`) }}
                        </button>
                    </div>
                    <button
                        type="button"
                        class="cursor-pointer rounded-md border border-line px-2 py-1 text-xs italic"
                        :class="single.italic ? 'bg-accent-600/15 text-primary' : 'bg-transparent text-muted'"
                        v-on:click="set('italic', single.italic ? null : true)"
                    >
                        {{ t("backend.studio.decks.free.italic") }}
                    </button>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <FreeNumberField
                        :model-value="(single.padX ?? 0) / 10"
                        :label="t('backend.studio.decks.free.pad_x')"
                        :min="0"
                        :max="20"
                        :step="0.1"
                        v-on:update:model-value="(value) => set('padX', value > 0 ? value * 10 : null)"
                    />
                    <FreeNumberField
                        :model-value="(single.padY ?? 0) / 10"
                        :label="t('backend.studio.decks.free.pad_y')"
                        :min="0"
                        :max="20"
                        :step="0.1"
                        v-on:update:model-value="(value) => set('padY', value > 0 ? value * 10 : null)"
                    />
                </div>
                <FreeColourField
                    :model-value="single.color ?? 'ink'"
                    :label="t('backend.studio.decks.free.text_colour')"
                    :appearance="appearance"
                    v-on:update:model-value="(value) => set('color', value === 'ink' ? null : value)"
                />
                <p
                    v-if="legibility"
                    class="m-0 rounded-md border px-2 py-1.5 text-xs"
                    :class="legibility.level === 'poor' ? 'border-rose-500/40 bg-rose-500/10 text-rose-300' : 'border-amber-500/40 bg-amber-500/10 text-amber-300'"
                    role="status"
                >
                    {{ t(`backend.studio.decks.free.contrast_${legibility.level}`, { ratio: legibility.ratio }) }}
                </p>
                <AppToggle
                    :model-value="single.autofit !== false"
                    :label="t('backend.studio.decks.free.autofit')"
                    :hint="t('backend.studio.decks.free.autofit_hint')"
                    v-on:update:model-value="(value) => set('autofit', value)"
                />
                <p class="m-0 text-xs text-muted">{{ t("backend.studio.decks.free.text_hint") }}</p>
            </section>

            <!-- Une image. -->
            <section v-if="type === 'image'" class="flex flex-col gap-3 border-t border-line pt-3">
                <AppButton variant="ghost" size="sm" class="w-fit" v-on:click="replacePicture">{{ t("backend.studio.decks.free.replace") }}</AppButton>
                <div class="grid grid-cols-2 gap-2">
                    <AppSelect :model-value="single.fit ?? 'cover'" :options="fitOptions" :label="t('backend.studio.decks.free.fit')" v-on:update:model-value="(value) => set('fit', value)" />
                    <AppSelect :model-value="single.mask ?? 'none'" :options="maskOptions" :label="t('backend.studio.decks.free.mask')" v-on:update:model-value="(value) => set('mask', value === 'none' ? null : value)" />
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-24 shrink-0 text-xs text-muted">{{ t("backend.studio.decks.free.zoom") }}</span>
                    <AppRange
                        :model-value="single.zoom ?? 1"
                        :min="1"
                        :max="5"
                        :step="0.05"
                        v-on:update:model-value="(value) => set('zoom', value > 1 ? value : null)"
                    />
                    <span class="w-10 shrink-0 text-right text-xs tabular-nums text-muted">× {{ (single.zoom ?? 1).toFixed(2) }}</span>
                </div>
                <AppFocalPointField
                    v-if="single.mediaUrl"
                    :src="single.mediaUrl"
                    :label="t('backend.studio.decks.free.focus')"
                    :hint="t('backend.studio.decks.free.focus_hint')"
                    :x="focus.x"
                    :y="focus.y"
                    :inherited="single.mediaFocusDefault ?? '50% 50%'"
                    fit-class="object-cover"
                    v-on:update:x="(value) => writeFocus('x', value)"
                    v-on:update:y="(value) => writeFocus('y', value)"
                />
                <details class="rounded-md border border-line p-2">
                    <summary class="cursor-pointer text-xs font-medium uppercase tracking-wide text-secondary">{{ t("backend.studio.decks.free.adjust") }}</summary>
                    <div class="mt-2 flex flex-col gap-2">
                        <div v-for="(bounds, name) in options.filters ?? {}" :key="name" class="flex items-center gap-2">
                            <span class="w-24 shrink-0 text-xs text-muted">{{ t(`backend.studio.decks.free.filters.${name}`) }}</span>
                            <AppRange :model-value="filterValue(name)" :min="bounds[0]" :max="bounds[1]" v-on:update:model-value="(value) => writeFilter(name, value)" />
                            <span class="w-10 shrink-0 text-right text-xs tabular-nums text-muted">{{ filterValue(name) }}</span>
                        </div>
                        <AppButton variant="ghost" size="sm" class="w-fit" v-on:click="set('filters', null)">{{ t("backend.studio.decks.free.reset_filters") }}</AppButton>
                    </div>
                </details>
            </section>

            <!-- Un film. -->
            <section v-if="type === 'video'" class="flex flex-col gap-3 border-t border-line pt-3">
                <AppButton variant="ghost" size="sm" class="w-fit" v-on:click="replaceFilm">{{ t("backend.studio.decks.free.replace") }}</AppButton>
                <div class="grid grid-cols-2 gap-2">
                    <AppSelect :model-value="single.fit ?? 'cover'" :options="fitOptions" :label="t('backend.studio.decks.free.fit')" v-on:update:model-value="(value) => set('fit', value)" />
                    <AppSelect :model-value="single.mask ?? 'none'" :options="maskOptions" :label="t('backend.studio.decks.free.mask')" v-on:update:model-value="(value) => set('mask', value === 'none' ? null : value)" />
                </div>
                <AppToggle :model-value="single.autoplay === true" :label="t('backend.studio.decks.free.autoplay')" :hint="t('backend.studio.decks.free.autoplay_hint')" v-on:update:model-value="(value) => set('autoplay', value || null)" />
                <AppToggle :model-value="single.loop === true" :label="t('backend.studio.decks.free.loop')" v-on:update:model-value="(value) => set('loop', value || null)" />
                <AppToggle :model-value="single.muted === true || single.autoplay === true" :label="t('backend.studio.decks.free.muted')" :disabled="single.autoplay === true" v-on:update:model-value="(value) => set('muted', value || null)" />
                <AppToggle :model-value="single.controls === true" :label="t('backend.studio.decks.free.controls')" v-on:update:model-value="(value) => set('controls', value || null)" />
            </section>

            <!-- Un lien YouTube ou Vimeo. -->
            <section v-if="type === 'embed'" class="flex flex-col gap-3 border-t border-line pt-3">
                <AppInput
                    :model-value="single.url ?? ''"
                    :label="t('backend.studio.decks.free.embed_url')"
                    :placeholder="t('backend.studio.decks.free.embed_placeholder')"
                    :hint="t('backend.studio.decks.free.embed_hint')"
                    v-on:update:model-value="writeEmbed"
                />
            </section>

            <!-- Une forme. -->
            <section v-if="type === 'shape'" class="flex flex-col gap-3 border-t border-line pt-3">
                <AppSelect :model-value="single.shape" :options="shapeOptions" :label="t('backend.studio.decks.free.shape')" v-on:update:model-value="(value) => set('shape', value)" />
                <AppSelect
                    v-if="single.shape === 'line'"
                    :model-value="single.head ?? 'none'"
                    :options="headOptions"
                    :label="t('backend.studio.decks.free.head')"
                    v-on:update:model-value="(value) => set('head', value)"
                />
            </section>

            <!-- Une icône. -->
            <section v-if="type === 'icon'" class="flex flex-col gap-3 border-t border-line pt-3">
                <AppSelect :model-value="single.icon" :options="iconOptions" :label="t('backend.studio.decks.free.icon')" v-on:update:model-value="(value) => set('icon', value)" />
                <FreeColourField :model-value="single.color ?? 'accent'" :label="t('backend.studio.decks.free.colour')" :appearance="appearance" v-on:update:model-value="(value) => set('color', value)" />
                <div class="flex items-center gap-2">
                    <span class="w-24 shrink-0 text-xs text-muted">{{ t("backend.studio.decks.free.line_weight") }}</span>
                    <AppRange
                        :model-value="single.strokeWidth ?? 2"
                        :min="0.5"
                        :max="4"
                        :step="0.25"
                        v-on:update:model-value="(value) => set('strokeWidth', value)"
                    />
                </div>
            </section>

            <!-- Un graphique, un tableau. -->
            <section v-if="type === 'chart'" class="flex flex-col gap-3 border-t border-line pt-3">
                <AppSelect :model-value="single.chartType ?? 'bar'" :options="chartOptions" :label="t('backend.studio.decks.slots.chartType')" v-on:update:model-value="(value) => set('chartType', value)" />
                <AppTextarea
                    :model-value="linesOf('series')"
                    :label="t('backend.studio.decks.slots.series')"
                    :placeholder="t('backend.studio.decks.line_placeholders.series')"
                    :rows="5"
                    v-on:update:model-value="(value) => writeLines('series', value)"
                />
                <FreeColourField
                    :model-value="single.color ?? 'accent'"
                    :label="t('backend.studio.decks.free.colour')"
                    :appearance="appearance"
                    :alpha="false"
                    v-on:update:model-value="(value) => set('color', value === 'accent' ? null : value)"
                />
            </section>

            <section v-if="type === 'table'" class="flex flex-col gap-3 border-t border-line pt-3">
                <AppTextarea
                    :model-value="linesOf('rows')"
                    :label="t('backend.studio.decks.slots.rows')"
                    :placeholder="t('backend.studio.decks.line_placeholders.rows')"
                    :rows="5"
                    v-on:update:model-value="(value) => writeLines('rows', value)"
                />
                <FreeNumberField
                    :model-value="(single.size ?? 22) / 10"
                    :label="t('backend.studio.decks.free.size')"
                    :min="0.5"
                    :max="20"
                    :step="0.1"
                    v-on:update:model-value="(value) => set('size', value * 10)"
                />
                <FreeColourField :model-value="single.color ?? 'ink'" :label="t('backend.studio.decks.free.text_colour')" :appearance="appearance" v-on:update:model-value="(value) => set('color', value === 'ink' ? null : value)" />
            </section>

            <!-- Ce qui habille la boîte : remplissage, contour, coins, ombre. -->
            <section v-if="single" class="flex flex-col gap-3 border-t border-line pt-3">
                <FreePaintField
                    v-if="hasFill"
                    :model-value="single.fill ?? null"
                    :label="type === 'shape' ? t('backend.studio.decks.free.fill') : t('backend.studio.decks.free.box_fill')"
                    :appearance="appearance"
                    v-on:update:model-value="(value) => set('fill', value)"
                />

                <template v-if="hasStroke">
                    <AppToggle :model-value="!!strokeOf" :label="type === 'text' ? t('backend.studio.decks.free.text_outline') : t('backend.studio.decks.free.stroke')" v-on:update:model-value="toggleStroke" />
                    <div v-if="strokeOf" class="flex flex-col gap-2 pl-2">
                        <FreeColourField :model-value="strokeOf.color" :appearance="appearance" v-on:update:model-value="(value) => setIn('stroke', 'color', value ?? 'ink')" />
                        <div class="grid grid-cols-2 gap-2">
                            <FreeNumberField
                                :model-value="strokeOf.width / 10"
                                :label="t('backend.studio.decks.free.stroke_width')"
                                :min="0.1"
                                :max="10"
                                :step="0.1"
                                v-on:update:model-value="(value) => setIn('stroke', 'width', value * 10)"
                            />
                            <AppSelect
                                v-if="type !== 'text'"
                                :model-value="strokeOf.style ?? 'solid'"
                                :options="strokeStyleOptions"
                                :label="t('backend.studio.decks.free.stroke_style')"
                                v-on:update:model-value="(value) => setIn('stroke', 'style', value)"
                            />
                        </div>
                    </div>
                </template>

                <div v-if="hasRadius" class="flex items-center gap-2">
                    <span class="w-24 shrink-0 text-xs text-muted">{{ t("backend.studio.decks.free.radius") }}</span>
                    <AppRange
                        :model-value="single.radius ?? 0"
                        :min="0"
                        :max="200"
                        :step="2"
                        v-on:update:model-value="(value) => set('radius', value || null)"
                    />
                </div>

                <template v-if="hasShadow">
                    <AppToggle :model-value="shadowOn" :label="t('backend.studio.decks.free.shadow')" v-on:update:model-value="toggleShadow" />
                    <div v-if="single.shadow" class="flex flex-col gap-2 pl-2">
                        <FreeColourField :model-value="single.shadow.color" :appearance="appearance" v-on:update:model-value="(value) => setIn('shadow', 'color', value ?? '#00000059')" />
                        <div class="grid grid-cols-3 gap-2">
                            <FreeNumberField :model-value="single.shadow.x / 10" label="X" :step="0.1" v-on:update:model-value="(value) => setIn('shadow', 'x', value * 10)" />
                            <FreeNumberField :model-value="single.shadow.y / 10" label="Y" :step="0.1" v-on:update:model-value="(value) => setIn('shadow', 'y', value * 10)" />
                            <FreeNumberField
                                :model-value="single.shadow.blur / 10"
                                :label="t('backend.studio.decks.free.blur')"
                                :min="0"
                                :step="0.1"
                                v-on:update:model-value="(value) => setIn('shadow', 'blur', value * 10)"
                            />
                        </div>
                    </div>
                </template>
            </section>

            <!-- Comment l'élément entre, et où il mène. -->
            <section class="flex flex-col gap-3 border-t border-line pt-3">
                <div class="grid grid-cols-2 gap-2">
                    <AppSelect
                        :model-value="selected[0].enter ?? 'none'"
                        :options="enterOptions"
                        :label="t('backend.studio.decks.free.enter')"
                        v-on:update:model-value="(value) => set('enter', value === 'none' ? null : value)"
                    />
                    <FreeNumberField
                        :model-value="selected[0].reveal ?? 0"
                        :label="t('backend.studio.decks.free.reveal')"
                        :min="0"
                        :max="30"
                        v-on:update:model-value="(value) => set('reveal', value > 0 ? Math.round(value) : null)"
                    />
                    <template v-if="selected[0].enter">
                        <FreeNumberField
                            :model-value="selected[0].duration ?? 600"
                            :label="t('backend.studio.decks.free.duration')"
                            unit="ms"
                            :min="100"
                            :max="4000"
                            :step="50"
                            v-on:update:model-value="(value) => set('duration', value)"
                        />
                        <FreeNumberField
                            :model-value="selected[0].delay ?? 0"
                            :label="t('backend.studio.decks.free.delay')"
                            unit="ms"
                            :min="0"
                            :max="10000"
                            :step="50"
                            v-on:update:model-value="(value) => set('delay', value || null)"
                        />
                    </template>
                </div>
                <p class="m-0 text-xs text-muted">{{ t("backend.studio.decks.free.reveal_hint") }}</p>
                <AppInput
                    v-if="single"
                    :model-value="single.link ?? ''"
                    :label="t('backend.studio.decks.free.link')"
                    :placeholder="t('backend.studio.decks.free.link_placeholder')"
                    v-on:update:model-value="(value) => set('link', value || null)"
                />
            </section>
        </template>
    </div>
</template>
