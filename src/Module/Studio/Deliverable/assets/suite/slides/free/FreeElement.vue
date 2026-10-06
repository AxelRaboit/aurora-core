<script setup>
/**
 * One element of a free slide, drawn where and how it says.
 *
 * **Two boxes, and the split is the shadow's.** The outer box holds the
 * position, the turn, the transparency and the shadow; the inner one holds the
 * cut-out (mask, rounded corners, outline) and the paint. A `drop-shadow` on
 * the box that is cut would be cut with it; on the box around it, it follows
 * the cut, so a star casts a star.
 *
 * **What plays, and where.** A film plays where a slide is watched or
 * composed, and stands still as its poster where it is printed or shrunk to a
 * thumbnail. A YouTube player is an iframe only where the slide is watched:
 * in the editor an iframe would swallow the pointer, and the element could no
 * longer be picked up.
 */
import { computed, onMounted, ref, watch } from "vue";
import { Film, Image as ImageIcon, Play } from "lucide-vue-next";
import SlideChart from "../components/SlideChart.vue";
import { cells } from "../cells.js";
import { iconNamed } from "./icons.js";
import { BOXES, PATHS, maskImage } from "./shapes.js";
import { colour, filters, fontFamily, mask, paint, shadow, u } from "./model.js";
import { useTextFit } from "./useTextFit.js";

const props = defineProps({
    element: { type: Object, required: true },
    appearance: { type: Object, default: null },
    /** Font families of the catalogue and of uploaded files, by key. */
    families: { type: Object, default: () => ({}) },
    live: { type: Boolean, default: false },
    still: { type: Boolean, default: false },
    compact: { type: Boolean, default: false },
    /** Whether this element's turn has come, when the slide reveals. */
    shown: { type: Boolean, default: true },
    /** Being typed into, in the editor. */
    editing: { type: Boolean, default: false },
});

const emit = defineEmits(["text-input"]);

const element = computed(() => props.element);
const type = computed(() => element.value.type);

/**
 * Turned and flipped. The flip is on the outer box with the turn so a flipped
 * picture keeps its handles where the person sees them.
 */
const outer = computed(() => {
    const el = element.value;
    const transforms = [];

    if (el.rotate) transforms.push(`rotate(${el.rotate}deg)`);

    return {
        left: `${el.x}%`,
        top: `${el.y}%`,
        width: `${el.w}%`,
        height: `${el.h}%`,
        transform: transforms.length ? transforms.join(" ") : undefined,
        opacity: el.opacity ?? undefined,
        filter: shadow(el.shadow) ?? undefined,
        "--enter-duration": `${el.duration ?? 600}ms`,
        "--enter-delay": `${el.delay ?? 0}ms`,
    };
});

const flips = computed(() => {
    const scale = [];

    if (element.value.flipX) scale.push("scaleX(-1)");
    if (element.value.flipY) scale.push("scaleY(-1)");

    return scale.length ? scale.join(" ") : undefined;
});

/** A shape drawn as a box: the rectangle and the ellipse. */
const isBoxShape = computed(() => type.value === "shape" && element.value.shape in BOXES);
const isLine = computed(() => type.value === "shape" && element.value.shape === "line");
const isPathShape = computed(() => type.value === "shape" && !isBoxShape.value && !isLine.value);
const maskPath = computed(() => PATHS[element.value.shape] ?? "");

const strokeCss = computed(() => {
    const stroke = element.value.stroke;

    return stroke ? `${u(stroke.width)} ${stroke.style ?? "solid"} ${colour(stroke.color)}` : undefined;
});

const body = computed(() => {
    const el = element.value;
    const style = {};

    const background = paint(el.fill);
    if (background) style.background = background;

    const clip = mask(el.mask);

    if (isPathShape.value) {
        const image = maskImage(el.shape);

        style.maskImage = image;
        style.webkitMaskImage = image;
        style.maskSize = "100% 100%";
        style.webkitMaskSize = "100% 100%";
        style.maskRepeat = "no-repeat";
        style.webkitMaskRepeat = "no-repeat";
    } else if (clip) {
        style.clipPath = clip;
    } else if (isBoxShape.value && el.shape === "ellipse") {
        style.borderRadius = "50%";
    } else if (el.radius) {
        style.borderRadius = u(el.radius);
    }

    // A text's outline is the letters' own, drawn by the text; every other
    // box but the path shapes - whose outline is drawn by an SVG on top - and
    // the line - which is its stroke - takes it as a border.
    if (strokeCss.value && type.value !== "text" && !isPathShape.value && !isLine.value && !clip) {
        style.border = strokeCss.value;
    }

    if (flips.value && ["image", "video", "icon", "shape"].includes(type.value)) style.transform = flips.value;

    return style;
});

/** The words' own look. Every size is a share of the slide's width. */
const text = computed(() => {
    const el = element.value;

    return {
        fontFamily: fontFamily(el.font, props.families),
        fontSize: `calc(${u(el.size ?? 40)} * var(--fit, 1))`,
        fontWeight: el.weight ?? 400,
        fontStyle: el.italic ? "italic" : undefined,
        letterSpacing: el.spacing ? `${el.spacing / 1000}em` : undefined,
        lineHeight: el.lineHeight ?? 1.2,
        padding: el.padX || el.padY ? `${u(el.padY ?? 0)} ${u(el.padX ?? 0)}` : undefined,
        textAlign: el.align ?? "left",
        justifyContent: { top: "flex-start", middle: "center", bottom: "flex-end" }[el.valign ?? "top"],
        color: colour(el.color, "var(--slide-ink)"),
        textTransform: { upper: "uppercase", lower: "lowercase", title: "capitalize" }[el.case] ?? undefined,
        WebkitTextStroke: el.stroke ? `${u(el.stroke.width)} ${colour(el.stroke.color)}` : undefined,
    };
});

const { box: textBox, measure } = useTextFit(
    () => element.value.autofit !== false,
    () => [element.value.html, element.value.size, element.value.font, element.value.lineHeight, element.value.spacing, element.value.weight, element.value.case],
);

/**
 * The editable copy of the words.
 *
 * Bound once, when typing starts, and never again until it stops: a `v-html`
 * on a node being typed into resets it on every keystroke and throws the
 * caret back to the start.
 */
const editable = ref(null);

watch(
    () => props.editing,
    (now) => {
        if (!now || !editable.value) return;

        editable.value.innerHTML = element.value.html ?? "";
        editable.value.focus();

        // The caret at the end of the words, where a person picks up typing.
        const range = document.createRange();
        range.selectNodeContents(editable.value);
        range.collapse(false);
        const selection = window.getSelection();
        selection?.removeAllRanges();
        selection?.addRange(range);
    },
    { flush: "post" },
);

/**
 * Pasted as words, not as markup.
 *
 * What a clipboard carries from a web page is HTML with its own styles,
 * classes and, at worst, handlers; the box keeps the words and lets the
 * person style them here.
 */
function onPaste(event) {
    event.preventDefault();

    const words = event.clipboardData?.getData("text/plain") ?? "";

    document.execCommand("insertText", false, words);
}

function onInput() {
    emit("text-input", element.value.id, editable.value?.innerHTML ?? "");
    measure();
}

onMounted(() => {
    if (props.editing && editable.value) editable.value.innerHTML = element.value.html ?? "";
});

const picture = computed(() => ({
    objectFit: element.value.fit === "contain" ? "contain" : "cover",
    objectPosition: element.value.focus ?? element.value.mediaFocusDefault ?? "50% 50%",
    transform: element.value.zoom > 1 ? `scale(${element.value.zoom})` : undefined,
    transformOrigin: element.value.focus ?? element.value.mediaFocusDefault ?? "50% 50%",
    filter: filters(element.value.filters) ?? undefined,
}));

/** A film moves where the slide is composed or watched, not on paper. */
const playsFilm = computed(() => !props.compact && !props.still && !!element.value.videoUrl);

/** The player itself only where the slide is watched. */
const playsEmbed = computed(() => props.live && !!element.value.embedUrl);

const embedSrc = computed(() => {
    const url = element.value.embedUrl;

    if (!url) return null;

    return `${url}${url.includes("?") ? "&" : "?"}rel=0`;
});

const icon = computed(() => (type.value === "icon" ? iconNamed(element.value.icon) : null));

/** The deck's colour as a hexadecimal, for the chart, which mixes its own. */
function hex(value, fallback) {
    const look = props.appearance ?? {};

    if (!value) return fallback;
    if (["ink", "accent", "background"].includes(value)) return look[value] ?? fallback;

    return value.slice(0, 7);
}

const tableRows = computed(() => (element.value.rows ?? []).map((row) => cells(row)));

/** Where a click takes the reader, only where the slide is watched or read. */
const link = computed(() =>
    props.live && /^(?:https?:|mailto:)/i.test(element.value.link ?? "") ? element.value.link : null,
);

const entering = computed(() => props.live && props.shown && !!element.value.enter && element.value.enter !== "none");
</script>

<template>
    <div
        class="fe"
        :class="[
            `fe-${type}`,
            shown ? '' : 'is-held',
            entering ? `fe-enter fe-enter-${element.enter}` : '',
            editing ? 'is-editing' : '',
        ]"
        :data-free-id="element.id"
        :style="outer"
    >
        <component
            :is="link ? 'a' : 'div'"
            class="fe-body"
            :style="body"
            :href="link ?? undefined"
            :target="link ? '_blank' : undefined"
            :rel="link ? 'noopener' : undefined"
        >
            <!-- The words. The inner box is the one that gets measured: it is
                 the one that overflows, and the one the fitting shrinks. -->
            <div v-if="type === 'text'" ref="textBox" class="fe-text" :style="text">
                <div
                    v-if="editing"
                    ref="editable"
                    class="fe-words"
                    contenteditable="true"
                    spellcheck="true"
                    v-on:input="onInput"
                    v-on:paste="onPaste"
                />
                <div v-else class="fe-words" v-html="element.html" />
            </div>

            <template v-else-if="type === 'image'">
                <img
                    v-if="element.mediaUrl"
                    class="fe-media"
                    :src="element.mediaUrl"
                    :alt="element.mediaAlt ?? ''"
                    :style="picture"
                    draggable="false"
                >
                <span v-else class="fe-placeholder"><ImageIcon :stroke-width="1.5" /></span>
            </template>

            <template v-else-if="type === 'video'">
                <video
                    v-if="playsFilm"
                    :key="element.videoUrl"
                    class="fe-media"
                    :src="element.videoUrl"
                    :poster="element.poster ?? undefined"
                    :style="picture"
                    :autoplay="element.autoplay === true"
                    :muted="element.muted === true || element.autoplay === true"
                    :loop="element.loop === true"
                    :controls="live && element.controls === true"
                    playsinline
                    preload="metadata"
                />
                <img
                    v-else-if="element.poster"
                    class="fe-media"
                    :src="element.poster"
                    alt=""
                    :style="picture"
                    draggable="false"
                >
                <span v-else class="fe-placeholder"><Film :stroke-width="1.5" /></span>
            </template>

            <template v-else-if="type === 'embed'">
                <iframe
                    v-if="playsEmbed"
                    class="fe-media"
                    :src="embedSrc"
                    allow="autoplay; fullscreen; picture-in-picture; encrypted-media"
                    allowfullscreen
                    loading="lazy"
                    referrerpolicy="strict-origin-when-cross-origin"
                    :title="element.name ?? element.provider ?? ''"
                />
                <template v-else>
                    <img
                        v-if="element.thumbnail"
                        class="fe-media"
                        :src="element.thumbnail"
                        alt=""
                        draggable="false"
                    >
                    <span v-else class="fe-placeholder"><Film :stroke-width="1.5" /></span>
                    <span v-if="!compact" class="fe-play"><Play :stroke-width="2" /></span>
                </template>
            </template>

            <template v-else-if="isLine">
                <span class="fe-line" :style="{ '--line-width': u(element.stroke?.width ?? 6), '--line-colour': colour(element.stroke?.color, 'var(--slide-ink)') }">
                    <span v-if="element.head === 'both'" class="fe-line-head is-start" />
                    <span v-if="element.head === 'end' || element.head === 'both'" class="fe-line-head is-end" />
                </span>
            </template>

            <component
                :is="icon"
                v-else-if="type === 'icon' && icon"
                class="fe-icon"
                :style="{ color: colour(element.color, 'var(--slide-accent)') }"
                :stroke-width="element.strokeWidth ?? 2"
            />

            <SlideChart
                v-else-if="type === 'chart' && !compact"
                class="fe-chart"
                :rows="element.series ?? []"
                :kind="element.chartType ?? 'bar'"
                :ink="appearance?.ink ?? '#e6e9ef'"
                :accent="hex(element.color, appearance?.accent ?? '#58a6ff')"
            />
            <span v-else-if="type === 'chart'" class="fe-placeholder fe-chart-bars"><i /><i /><i /></span>

            <table
                v-else-if="type === 'table'"
                class="fe-table"
                :style="{ fontSize: u(element.size ?? 22), color: colour(element.color, 'var(--slide-ink)') }"
            >
                <thead v-if="tableRows.length">
                    <tr>
                        <th v-for="(cell, at) in tableRows[0]" :key="at">{{ cell }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, at) in tableRows.slice(1)" :key="at">
                        <td v-for="(cell, column) in row" :key="column">{{ cell }}</td>
                    </tr>
                </tbody>
            </table>
        </component>

        <!-- The outline of a shape drawn by a path: the same path, stroked on
             top, with a width that does not stretch with it. -->
        <svg
            v-if="isPathShape && element.stroke"
            class="fe-outline"
            viewBox="0 0 100 100"
            preserveAspectRatio="none"
            aria-hidden="true"
            :style="{ transform: flips }"
        >
            <path
                :d="maskPath"
                fill="none"
                :stroke="colour(element.stroke.color)"
                :stroke-dasharray="element.stroke.style === 'dashed' ? '6 4' : element.stroke.style === 'dotted' ? '1 3' : undefined"
                :style="{ strokeWidth: u(element.stroke.width) }"
                vector-effect="non-scaling-stroke"
                fill-rule="evenodd"
            />
        </svg>
    </div>
</template>

<style scoped>
.fe {
    position: absolute;
    box-sizing: border-box;
    transform-origin: 50% 50%;
}

.fe-body {
    position: absolute;
    inset: 0;
    overflow: hidden;
    box-sizing: border-box;
    color: inherit;
    text-decoration: none;
}

.fe-shape .fe-body,
.fe-icon .fe-body { overflow: visible; }

/* The words stack in a column, and the column sits at the top, middle or
   bottom of the box. */
.fe-text {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    overflow-wrap: break-word;
}

.fe-words { outline: none; min-height: 1em; white-space: pre-wrap; }
.fe-words :deep(p),
.fe-words :deep(div) { margin: 0; }
.fe-words :deep(ul),
.fe-words :deep(ol) { margin: 0; padding-left: 1.2em; }
.fe-words :deep(ul) { list-style: disc; }
.fe-words :deep(ol) { list-style: decimal; }
.fe-words :deep(li::marker) { color: var(--slide-accent); }
.is-editing .fe-words { cursor: text; caret-color: var(--slide-accent); }

.fe-media {
    display: block;
    width: 100%;
    height: 100%;
    border: 0;
    pointer-events: none;
    user-select: none;
}

/* The YouTube player does receive the pointer: it is a player. */
iframe.fe-media { pointer-events: auto; }

.fe-placeholder {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background:
        repeating-linear-gradient(45deg, color-mix(in srgb, currentColor 10%, transparent) 0 0.6cqw, transparent 0.6cqw 1.2cqw),
        color-mix(in srgb, var(--slide-ink) 6%, transparent);
    color: color-mix(in srgb, var(--slide-ink) 45%, transparent);
}

.fe-placeholder > svg { width: 22%; height: 22%; max-width: 6cqw; max-height: 6cqw; }

.fe-play {
    position: absolute;
    left: 50%;
    top: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 9cqw;
    max-width: 40%;
    aspect-ratio: 1;
    transform: translate(-50%, -50%);
    border-radius: 9999px;
    background: rgb(0 0 0 / 0.6);
    color: #fff;
}

.fe-play > svg { width: 45%; height: 45%; }

.fe-icon { display: block; width: 100%; height: 100%; }

.fe-outline {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    overflow: visible;
    pointer-events: none;
}

/* The line: a centred bar, with its heads set at its ends. */
.fe-line {
    position: absolute;
    left: 0;
    right: 0;
    top: 50%;
    height: var(--line-width);
    transform: translateY(-50%);
    background: var(--line-colour);
    border-radius: 9999px;
}

.fe-line-head {
    position: absolute;
    top: 50%;
    width: 0;
    height: 0;
    border-top: calc(var(--line-width) * 2.2) solid transparent;
    border-bottom: calc(var(--line-width) * 2.2) solid transparent;
    transform: translateY(-50%);
}

.fe-line-head.is-end { right: calc(var(--line-width) * -0.5); border-left: calc(var(--line-width) * 3.4) solid var(--line-colour); }
.fe-line-head.is-start { left: calc(var(--line-width) * -0.5); border-right: calc(var(--line-width) * 3.4) solid var(--line-colour); }

.fe-chart { position: absolute; inset: 0; }
.fe-chart-bars { gap: 6%; align-items: flex-end; padding: 12%; }
.fe-chart-bars > i { display: block; width: 18%; background: var(--slide-accent); opacity: 0.6; }
.fe-chart-bars > i:nth-child(1) { height: 40%; }
.fe-chart-bars > i:nth-child(2) { height: 70%; }
.fe-chart-bars > i:nth-child(3) { height: 55%; }

/* The table drawn like the template's: header in the heading font, accent
   rule under it, rows separated by a discreet line. */
.fe-table {
    width: 100%;
    border-collapse: collapse;
    font-family: var(--slide-body);
    line-height: 1.3;
}

.fe-table th {
    padding: 0 0 0.4em;
    text-align: left;
    font-family: var(--slide-heading);
    font-weight: 600;
    border-bottom: 0.3cqw solid var(--slide-accent);
}

.fe-table td {
    padding: 0.4em 0;
    border-bottom: 1px solid color-mix(in srgb, currentColor 15%, transparent);
}

.fe-table tr:last-child td { border-bottom: 0; }

.fe-table th + th,
.fe-table td + td { padding-left: 1em; }

/* A line not revealed yet keeps its place, as on a template slide. */
.fe.is-held { visibility: hidden; }

/* The entrances. Played once, when the element appears, and only where the
   slide is being watched: the player sets the class, nobody else. */
@media (prefers-reduced-motion: no-preference) {
    .fe-enter {
        animation-duration: var(--enter-duration, 600ms);
        animation-delay: var(--enter-delay, 0ms);
        animation-timing-function: cubic-bezier(0.22, 1, 0.36, 1);
        animation-fill-mode: both;
    }

    .fe-enter-fade { animation-name: fe-fade; }
    .fe-enter-rise { animation-name: fe-rise; }
    .fe-enter-fall { animation-name: fe-fall; }
    .fe-enter-left { animation-name: fe-left; }
    .fe-enter-right { animation-name: fe-right; }
    .fe-enter-zoom { animation-name: fe-zoom; }
    .fe-enter-pop { animation-name: fe-pop; }
    .fe-enter-blur { animation-name: fe-blur; }
}

/* `translate` and `scale` rather than `transform`: the element already
   carries its rotation in `transform`, and the animation would override it
   while entering. */
@keyframes fe-fade { from { opacity: 0; } }
@keyframes fe-rise { from { opacity: 0; translate: 0 4cqw; } }
@keyframes fe-fall { from { opacity: 0; translate: 0 -4cqw; } }
@keyframes fe-left { from { opacity: 0; translate: -6cqw 0; } }
@keyframes fe-right { from { opacity: 0; translate: 6cqw 0; } }
@keyframes fe-zoom { from { opacity: 0; scale: 0.6; } }
@keyframes fe-pop {
    0% { opacity: 0; scale: 0.4; }
    70% { opacity: 1; scale: 1.08; }
    100% { scale: 1; }
}
@keyframes fe-blur { from { opacity: 0; filter: blur(2cqw); } }
</style>
