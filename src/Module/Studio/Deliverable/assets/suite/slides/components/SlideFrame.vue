<script setup>
/**
 * One slide, drawn at the shape it will be shown in, in the deck's own colours.
 *
 * **A fixed 16:9 frame**, which is the whole reason this module has layouts
 * rather than a flowing grid: what the reader arranges here is what lands on
 * the wall, and a frame that reflowed would be a preview that lies. The frame
 * scales with its container and its type scales with it, through `cqw` units,
 * so the same component is a thumbnail in the list and the full preview beside
 * the form without drawing twice.
 *
 * **The look arrives as custom properties, resolved on the server.** Five
 * places draw this component - the thumbnails, the editor's preview, the
 * player, the print page and the public share link - and each of them would
 * otherwise have to merge the theme with the deck's overrides the same way.
 * `DeckAppearance` does it once; here there is nothing to decide, only
 * properties to set.
 */
import { computed } from "vue";
import { cells, decorated, headed, measured } from "../cells.js";
import { iconFor } from "../icons.js";
import { useSlideFit } from "../composables/useSlideFit.js";
import { emphasis } from "../emphasis.js";
import SlideChart from "./SlideChart.vue";
import FreeLayer from "../free/FreeLayer.vue";
import { paint } from "../free/model.js";

const props = defineProps({
    slide: { type: Object, required: true },
    /** Thumbnails drop the body text: at 160px nothing of it is legible. */
    compact: { type: Boolean, default: false },
    /**
     * The deck's resolved look. Absent on a frame drawn outside a deck, which
     * then falls back to the back office's own surface, exactly as before.
     */
    appearance: { type: Object, default: null },
    /** 1-based, for the slide number in the footer. */
    index: { type: Number, default: 0 },
    /**
     * Drawn where it is watched rather than read: the player and the presenter.
     * Movement belongs to the act of presenting, exactly as the transition does.
     */
    live: { type: Boolean, default: false },
    /**
     * How many lines of this slide's list are out, or null for all of them.
     *
     * The hidden ones are drawn and made invisible rather than left out: the
     * frame measures its own type and shrinks it to fit, so a list that grew a
     * line at a time would resize every word on the slide at every press.
     */
    revealed: { type: Number, default: null },
    /**
     * Drawn on paper: the print page. Films stand still as their poster and
     * nothing enters, since a sheet catches whatever was on screen.
     */
    still: { type: Boolean, default: false },
    /** On a free slide in the editor, the text box being typed into. */
    editingId: { type: String, default: null },
});

const emit = defineEmits(["text-input"]);

/**
 * A free slide: drawn from its elements rather than from slots.
 *
 * The rest of the frame stays what it is for every slide - the deck's ground,
 * its texture and wash, a backdrop picture with its veil, the band, the
 * footer - so a free slide sits in its deck like any other, and only the
 * middle is the person's own.
 */
const isFree = computed(() => props.slide.layout === "free");

/** The paint under a free slide's elements, over the deck's ground. */
const fill = computed(() => (isFree.value ? paint(props.slide.content.fill) : null));

/**
 * The film behind a free slide.
 *
 * Its poster on a thumbnail and on paper, the film itself everywhere else,
 * silent and looping: a backdrop that stopped after eight seconds, or spoke
 * over the presenter, would be a backdrop nobody wanted.
 */
const backgroundFilm = computed(() => {
    if (!isFree.value || !props.slide.content.bgVideoUrl) return null;

    return {
        url: props.slide.content.bgVideoUrl,
        poster: props.slide.content.bgVideoPoster ?? null,
        plays: !props.compact && !props.still,
    };
});

/**
 * Which of the deck's three colours this slide stands on.
 *
 * **One setting and not two.** The first version of this was a boolean called
 * `inverted`, and a per-slide ground taken from the palette would have been a
 * second way to spell the same slide: ground-is-the-ink IS the inversion. One
 * slot with three values says it once, and makes room for the third ground the
 * boolean had nowhere to put.
 *
 * **Always the deck's own colours**, never a colour of its own: a slide painted
 * with a value of its own would drift the day the deck's palette is
 * overridden.
 *
 * The accent does not move when the ground does. It is the one tone that sits
 * at a deliberate distance from both others, and moving it too would leave the
 * wash and the rules on such a slide looking like another deck's.
 *
 * A frame drawn outside a deck has no palette to stand on, so it stays as it
 * is rather than inventing one.
 */
const ground = computed(() =>
    ["inverted", "accent"].includes(props.slide.content.ground)
        ? props.slide.content.ground
        : "normal",
);

/**
 * The picture's outline, as a name the stylesheet matches on.
 *
 * **No full-bleed case**, although it is the first one anybody asks for: a
 * picture that reaches all four edges of the frame with the text over it is
 * what `bgMediaId` already draws, on every layout, with a veil to keep the
 * words readable. A second way to spell it would be two features that look the
 * same until one of them gets the veil and the other does not.
 */
const shape = computed(() => {
    const asked = props.slide.content.mediaShape;

    return ["soft", "round", "arch", "circle"].includes(asked) ? asked : "soft";
});


/** Whether the line at this rank is out yet. Everything is, unless told. */
const isOut = (at) => props.revealed === null || at < props.revealed;

const skin = computed(() => {
    const look = props.appearance;

    if (!look) return {};

    return {
        // The accent ground borrows the deck's background for its text: it is
        // the one tone guaranteed to sit at a distance from the accent, since
        // the palette was picked so the ink reads on it.
        "--slide-bg": { inverted: look.ink, accent: look.accent }[ground.value] ?? look.background,
        "--slide-ink": "normal" === ground.value ? look.ink : look.background,
        // And on that ground the accent has to move, which it does nowhere
        // else. Everything this module draws as furniture - the band, the
        // frame's hairline, the bullets, the rules, an accented word - is
        // painted in the accent, and on a ground that IS the accent all of it
        // simply vanishes. The deck's ink takes the role there: dark furniture
        // on the accent, under the pale text, still three colours and still
        // the deck's own.
        "--slide-accent": "accent" === ground.value ? look.ink : look.accent,
        "--slide-heading": look.headingFont,
        "--slide-body": look.bodyFont,
    };
});

/**
 * The wash over the ground, as a name the stylesheet matches on.
 *
 * A name rather than a computed `background-image`: where the accent starts
 * and how far it reaches is a drawing decision, and drawing decisions in this
 * component live in its stylesheet with the rest of the `color-mix` work.
 */
const gradient = computed(() => props.appearance?.gradient ?? "none");

/** The texture on the ground, which a picture on the ground simply covers. */
const pattern = computed(() => props.appearance?.pattern ?? "none");

/** The frame's own margin, and the hairline drawn inside it. */
const margins = computed(() => props.appearance?.margins ?? "normal");
const hairline = computed(() => props.appearance?.hairline === true);

/** How titles are cased, and what a bullet looks like. Both deck-wide. */
const titleCase = computed(() => props.appearance?.titleCase ?? "normal");
const bullets = computed(() => props.appearance?.bullets ?? "disc");

/**
 * The very large, very pale figure behind a section title.
 *
 * Typed rather than counted. The frame knows its index in the deck, not its
 * rank among the sections, and a figure that renumbered itself every time a
 * slide moved would be a decoration nobody could rely on.
 */
const ghost = computed(() => (props.compact ? "" : (props.slide.content.ghost ?? "")));

/**
 * Where the content sits, how it is aligned, how wide it runs.
 *
 * **Undefined rather than a default when nothing was chosen**, so the
 * attribute is absent and the layout's own rule keeps applying. The section
 * layout centres its title in the stylesheet; emitting `align="left"` on every
 * slide that never expressed a preference would quietly restyle every section
 * slide ever written.
 */
const composition = computed(() => {
    const content = props.slide.content;

    return {
        "data-anchor": ["top", "center", "bottom"].includes(content.anchor) ? content.anchor : undefined,
        "data-align": ["left", "center", "right"].includes(content.align) ? content.align : undefined,
        "data-measure": ["full", "two_thirds", "half"].includes(content.measure) ? content.measure : undefined,
    };
});

/**
 * The logo shows on the cover when it was asked for on the cover.
 *
 * The cover is the first slide, whatever its layout: a deck that opens on a
 * full-page image has no `title` slide and would otherwise never show the mark.
 */
const showsLogo = computed(() => {
    const placement = props.appearance?.logoPlacement ?? "none";

    if (props.compact || !props.appearance?.logoUrl) return false;

    return placement === "every" || (placement === "cover" && props.index === 1);
});

const footerText = computed(() => (props.compact ? "" : (props.appearance?.footerText ?? "")));

/** The cover carries no number: "1" under a title slide reads as a typo. */
const showsNumber = computed(
    () => !props.compact && props.appearance?.slideNumbers === true && props.index > 1,
);

const hasFooter = computed(() => showsLogo.value || !!footerText.value || showsNumber.value);

/**
 * The picture behind everything, and the veil that keeps the text readable.
 *
 * The veil is the deck's own background colour at the chosen strength rather
 * than a flat black: on a paper theme a black veil turns a light slide grey,
 * which is the one thing a light theme was chosen to avoid. Dimming towards
 * the ground keeps the slide recognisably the deck's.
 */
const background = computed(() => {
    const content = props.slide.content;
    const url = content.bgMediaUrl;

    if (!url) return null;

    const treatment = ["blur", "mono", "duotone", "grain"].includes(content.bgTreatment)
        ? content.bgTreatment
        : "none";

    return {
        url,
        dim: Math.min(Math.max(content.bgDim ?? 40, 0), 90) / 100,
        treatment,
        // The tint and the grain are a layer of their own, between the picture
        // and the veil: a filter cannot add a colour, and a `::after` on the
        // backdrop would paint over the veil instead of under it.
        film: "duotone" === treatment || "grain" === treatment,
        veil: ["flat", "bottom", "top"].includes(content.bgVeil) ? content.bgVeil : "flat",
    };
});

/**
 * The very slow travel across a background picture.
 *
 * **Only where a slide is watched.** The print page and the share link draw
 * the same component, and a picture that drifts under somebody reading a PDF
 * is a picture that will be photographed mid-move. The player says so by
 * passing `live`; everywhere else the class is simply never added.
 *
 * The direction comes from the focal point already stored on the slide: a
 * picture whose subject is on the left is worth travelling towards the left.
 */
const drifts = computed(() => props.live && props.slide.content.drift === true);

/**
 * How loud the title is on this slide, before the fit shrinks anything.
 *
 * **A multiplier on the starting point and not a size.** Every size in the
 * frame is `Xcqw * var(--fit)`, and `useSlideFit` lowers `--fit` until the
 * words stop falling out. A scale that set a size outright would be a value
 * fighting that measurement; one that multiplies the start is simply a taller
 * starting point for the same ladder to come down.
 */
const titleScale = computed(
    () => ({ quiet: 0.72, loud: 1.35 })[props.slide.content.titleScale] ?? 1,
);

/** The corners, darkened. Works on a flat ground as well as on a picture. */
const vignette = computed(() => props.slide.content.vignette === true);

/**
 * A solid shape of accent against one edge of the frame.
 *
 * **Decided per slide and not per deck**, unlike the wash and the texture: the
 * band is opaque and takes a third of the frame, so on every slide of a deck
 * it stops being furniture and becomes the layout. On a cover and a section
 * slide it is exactly what makes them read as composed.
 */
const band = computed(() =>
    ["left", "bottom", "edge"].includes(props.slide.content.band)
        ? props.slide.content.band
        : "none",
);

/** The hairlines a deck draws between its columns and under its kickers. */
const rules = computed(() => props.appearance?.rules === true);

/** What the picture of a picture layout is wrapped in. */
const mediaFrame = computed(() =>
    ["line", "shadow"].includes(props.slide.content.mediaFrame)
        ? props.slide.content.mediaFrame
        : "none",
);

/** The line above the title. Empty on a thumbnail, where it would be one pixel. */
const kicker = computed(() => (props.compact ? "" : (props.slide.content.kicker ?? "")));

/**
 * How the picture fills its box, and what stays when it cannot all fit.
 *
 * The focus is the slide's own choice when it made one, else the point the
 * document itself carries. A document photographed with its subject low in the
 * frame keeps that point across every deck that uses it, which is the whole
 * reason it is stored on the document.
 */
const media = computed(() => ({
    "--media-fit": props.slide.content.mediaFit === "cover" ? "cover" : "contain",
    "--media-focus":
        props.slide.content.mediaFocus ??
        props.slide.content.mediaFocusDefault ??
        "50% 50%",
}));

// Re-measured when the words change, which in the editor is on every keystroke.
const { stage, fit } = useSlideFit(() => [props.slide.content, props.slide.layout]);
</script>

<template>
    <div class="slide-ratio">
        <div
            class="slide-frame"
            :class="[compact ? 'is-compact' : '', hasFooter ? 'has-footer' : '']"
            :data-gradient="gradient"
            :data-pattern="pattern"
            :data-shape="shape"
            :data-margins="margins"
            :data-title-case="titleCase"
            :data-bullets="bullets"
            :data-media-frame="mediaFrame"
            :data-band="band"
            :data-rules="rules ? 'on' : 'off'"
            :data-bleed="slide.content.mediaBleed === true ? 'on' : 'off'"
            v-bind="composition"
            :style="[skin, media, { '--title-scale': titleScale }]"
        >
            <span v-if="fill" class="sf-fill" :style="{ background: fill }" aria-hidden="true" />

            <span v-if="pattern !== 'none'" class="sf-pattern" aria-hidden="true" />

            <div
                v-if="background"
                class="sf-backdrop"
                :data-treatment="background.treatment"
                :data-veil="background.veil"
                aria-hidden="true"
            >
                <img class="sf-backdrop-file" :class="drifts ? 'is-drifting' : ''" :src="background.url" alt="">
                <span v-if="background.film" class="sf-backdrop-film" />
                <span
                    class="sf-backdrop-veil"
                    :style="{ opacity: background.dim }"
                />
            </div>

            <div v-if="backgroundFilm" class="sf-backdrop" aria-hidden="true">
                <video
                    v-if="backgroundFilm.plays"
                    class="sf-backdrop-file"
                    :src="backgroundFilm.url"
                    :poster="backgroundFilm.poster ?? undefined"
                    autoplay
                    muted
                    loop
                    playsinline
                />
                <img v-else-if="backgroundFilm.poster" class="sf-backdrop-file" :src="backgroundFilm.poster" alt="">
            </div>

            <span v-if="vignette" class="sf-vignette" aria-hidden="true" />

            <span v-if="gradient !== 'none'" class="sf-wash" aria-hidden="true" />

            <span v-if="hairline" class="sf-hairline" aria-hidden="true" />

            <span v-if="band !== 'none'" class="sf-band" aria-hidden="true" />

            <FreeLayer
                v-if="isFree"
                :elements="slide.content.elements ?? []"
                :appearance="appearance"
                :live="live"
                :still="still"
                :compact="compact"
                :revealed="revealed"
                :editing-id="editingId"
                v-on:text-input="(id, html) => emit('text-input', id, html)"
            />

            <div v-else ref="stage" class="slide-stage" :style="{ '--fit': fit }">
                <p v-if="kicker" class="sf-kicker">{{ kicker }}</p>

                <template v-if="slide.layout === 'title'">
                    <p class="sf-title" v-html="emphasis(slide.content.title)" />
                    <p v-if="!compact && slide.content.subtitle" class="sf-subtitle" v-html="emphasis(slide.content.subtitle)" />
                </template>

                <template v-else-if="slide.layout === 'section'">
                    <span v-if="ghost" class="sf-ghost" aria-hidden="true">{{ ghost }}</span>
                    <p class="sf-section" v-html="emphasis(slide.content.title)" />
                </template>

                <template v-else-if="slide.layout === 'bullets'">
                    <p class="sf-heading" v-html="emphasis(slide.content.title)" />
                    <ul v-if="!compact" class="sf-list">
                        <li
                            v-for="(bullet, at) in slide.content.bullets ?? []"
                            :key="at"
                            :class="isOut(at) ? '' : 'is-held'"
                            v-html="emphasis(bullet)"
                        />
                    </ul>
                    <div v-else class="sf-lines">
                        <span v-for="(bullet, at) in (slide.content.bullets ?? []).slice(0, 4)" :key="at" />
                    </div>
                </template>

                <template v-else-if="slide.layout === 'quote'">
                    <p class="sf-quote" v-html="emphasis(slide.content.quote)" />
                    <p v-if="slide.content.attribution" class="sf-attribution" v-html="emphasis(slide.content.attribution)" />
                </template>

                <template v-else-if="slide.layout === 'split'">
                    <p class="sf-heading" v-html="emphasis(slide.content.title)" />
                    <div class="sf-columns">
                        <p v-html="compact ? '' : emphasis(slide.content.left)" />
                        <p v-html="compact ? '' : emphasis(slide.content.right)" />
                    </div>
                </template>

                <template v-else-if="slide.layout === 'stat'">
                    <p class="sf-stat">{{ slide.content.value }}</p>
                    <p v-if="!compact && slide.content.label" class="sf-stat-label" v-html="emphasis(slide.content.label)" />
                </template>

                <template v-else-if="slide.layout === 'image_text'">
                    <p v-if="slide.content.title" class="sf-heading sf-heading-small" v-html="emphasis(slide.content.title)" />
                    <div class="sf-beside" :class="slide.content.side === 'right' ? 'is-right' : ''">
                        <div class="sf-beside-media">
                            <img
                                v-if="slide.content.mediaUrl"
                                class="sf-image-file"
                                :src="slide.content.mediaUrl"
                                :alt="slide.content.mediaAlt ?? ''"
                            >
                            <span v-else class="sf-image-mark" />
                        </div>
                        <p v-if="!compact" class="sf-beside-text" v-html="emphasis(slide.content.text)" />
                    </div>
                </template>

                <template v-else-if="slide.layout === 'compare'">
                    <p v-if="slide.content.title" class="sf-heading sf-heading-small" v-html="emphasis(slide.content.title)" />
                    <div class="sf-compare">
                        <div class="sf-compare-side">
                            <span v-if="slide.content.leftTitle" class="sf-compare-head" v-html="emphasis(slide.content.leftTitle)" />
                            <p v-if="!compact" v-html="emphasis(slide.content.left)" />
                        </div>
                        <div class="sf-compare-side is-second">
                            <span v-if="slide.content.rightTitle" class="sf-compare-head" v-html="emphasis(slide.content.rightTitle)" />
                            <p v-if="!compact" v-html="emphasis(slide.content.right)" />
                        </div>
                    </div>
                </template>

                <template v-else-if="slide.layout === 'figures'">
                    <p v-if="slide.content.title" class="sf-heading sf-heading-small" v-html="emphasis(slide.content.title)" />
                    <div class="sf-figures" :style="{ '--figures': Math.min((slide.content.figures ?? []).length || 1, 4) }">
                        <div v-for="(figure, at) in (slide.content.figures ?? []).slice(0, 4)" :key="at" class="sf-figure" :class="isOut(at) ? '' : 'is-held'">
                            <span class="sf-figure-value">{{ measured(figure).value }}</span>
                            <span v-if="measured(figure).share !== null" class="sf-gauge">
                                <i :style="{ width: measured(figure).share + '%' }" />
                            </span>
                            <span v-if="!compact && measured(figure).label" class="sf-figure-label" v-html="emphasis(measured(figure).label)" />
                        </div>
                    </div>
                </template>

                <template v-else-if="slide.layout === 'logos'">
                    <p v-if="slide.content.title" class="sf-heading sf-heading-small" v-html="emphasis(slide.content.title)" />
                    <div class="sf-logos" :style="{ '--logos': Math.min((slide.content.mediaPictures ?? []).length || 1, 4) }">
                        <span v-for="(picture, at) in slide.content.mediaPictures ?? []" :key="at" class="sf-logos-cell">
                            <img v-if="picture" :src="picture.url" :alt="picture.alt">
                        </span>
                    </div>
                </template>

                <template v-else-if="slide.layout === 'mosaic'">
                    <p v-if="slide.content.title" class="sf-heading sf-heading-small" v-html="emphasis(slide.content.title)" />
                    <div class="sf-mosaic" :data-count="Math.min((slide.content.mediaPictures ?? []).length, 8)">
                        <span v-for="(picture, at) in slide.content.mediaPictures ?? []" :key="at" class="sf-mosaic-cell">
                            <img v-if="picture" :src="picture.url" :alt="picture.alt" :style="{ objectPosition: picture.focus }">
                        </span>
                    </div>
                </template>

                <template v-else-if="slide.layout === 'agenda'">
                    <p v-if="slide.content.title" class="sf-heading sf-heading-small" v-html="emphasis(slide.content.title)" />
                    <ol class="sf-agenda">
                        <li
                            v-for="(step, at) in slide.content.steps ?? []"
                            :key="at"
                            :class="[at + 1 === slide.content.current ? 'is-current' : '', isOut(at) ? '' : 'is-held']"
                        >
                            <span class="sf-agenda-rank">{{ String(at + 1).padStart(2, "0") }}</span>
                            <span v-html="emphasis(headed(step).head)" />
                        </li>
                    </ol>
                </template>

                <template v-else-if="slide.layout === 'portrait'">
                    <div class="sf-portrait">
                        <div class="sf-portrait-face">
                            <img
                                v-if="slide.content.mediaUrl"
                                class="sf-image-file"
                                :src="slide.content.mediaUrl"
                                :alt="slide.content.mediaAlt ?? ''"
                            >
                            <span v-else class="sf-image-mark" />
                        </div>
                        <div class="sf-portrait-words">
                            <p class="sf-portrait-quote" v-html="emphasis(slide.content.quote)" />
                            <p v-if="!compact && slide.content.attribution" class="sf-portrait-who">
                                <span v-html="emphasis(slide.content.attribution)" />
                                <span v-if="slide.content.role" class="sf-portrait-role" v-html="emphasis(slide.content.role)" />
                            </p>
                        </div>
                    </div>
                </template>

                <template v-else-if="slide.layout === 'end'">
                    <p class="sf-title" v-html="emphasis(slide.content.title)" />
                    <div v-if="!compact" class="sf-end-lines">
                        <span
                            v-for="(line, at) in slide.content.lines ?? []"
                            :key="at"
                            :class="isOut(at) ? '' : 'is-held'"
                            v-html="emphasis(line)"
                        />
                    </div>
                </template>

                <template v-else-if="slide.layout === 'cards'">
                    <p v-if="slide.content.title" class="sf-heading sf-heading-small" v-html="emphasis(slide.content.title)" />
                    <div class="sf-cards" :style="{ '--cards': Math.min((slide.content.items ?? []).length || 1, 4) }">
                        <div v-for="(item, at) in slide.content.items ?? []" :key="at" class="sf-card" :class="isOut(at) ? '' : 'is-held'">
                            <component
                                :is="iconFor(decorated(item).icon)"
                                v-if="!compact && iconFor(decorated(item).icon)"
                                class="sf-icon"
                                :stroke-width="2"
                            />
                            <span v-if="decorated(item).badge" class="sf-badge">{{ decorated(item).badge }}</span>
                            <span class="sf-card-head" v-html="emphasis(decorated(item).head)" />
                            <span v-if="!compact && decorated(item).body" class="sf-card-body" v-html="emphasis(decorated(item).body)" />
                        </div>
                    </div>
                </template>

                <template v-else-if="slide.layout === 'timeline'">
                    <p v-if="slide.content.title" class="sf-heading sf-heading-small" v-html="emphasis(slide.content.title)" />
                    <ol class="sf-steps">
                        <li v-for="(step, at) in slide.content.steps ?? []" :key="at" class="sf-step" :class="isOut(at) ? '' : 'is-held'">
                            <component
                                :is="iconFor(decorated(step).icon)"
                                v-if="!compact && iconFor(decorated(step).icon)"
                                class="sf-step-icon"
                                :stroke-width="2"
                            />
                            <span v-else class="sf-step-mark" />
                            <span class="sf-step-head" v-html="emphasis(decorated(step).head)" />
                            <span v-if="decorated(step).badge" class="sf-badge">{{ decorated(step).badge }}</span>
                            <span v-if="!compact && decorated(step).body" class="sf-step-body" v-html="emphasis(decorated(step).body)" />
                        </li>
                    </ol>
                </template>

                <template v-else-if="slide.layout === 'table'">
                    <p v-if="slide.content.title" class="sf-heading sf-heading-small" v-html="emphasis(slide.content.title)" />
                    <table v-if="!compact" class="sf-table">
                        <!-- The first row is the header, and that is a
                             convention of the template: a slide table without
                             a header is a grid of numbers without a legend. -->
                        <thead v-if="(slide.content.rows ?? []).length">
                            <tr>
                                <th v-for="(cell, at) in cells(slide.content.rows[0])" :key="at">{{ cell }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, at) in (slide.content.rows ?? []).slice(1)" :key="at">
                                <td v-for="(cell, column) in cells(row)" :key="column" v-html="emphasis(cell)" />
                            </tr>
                        </tbody>
                    </table>
                    <div v-else class="sf-lines">
                        <span v-for="(row, at) in (slide.content.rows ?? []).slice(0, 4)" :key="at" />
                    </div>
                </template>

                <template v-else-if="slide.layout === 'chart'">
                    <p v-if="slide.content.title" class="sf-heading sf-heading-small" v-html="emphasis(slide.content.title)" />
                    <!-- No canvas in a 160 px thumbnail: one Chart.js canvas
                         per slide in the column is a dozen rendering contexts
                         for bars three pixels tall. The grey bars say there is
                         a chart there, which is all a thumbnail has to say. -->
                    <SlideChart
                        v-if="!compact"
                        :rows="slide.content.series ?? []"
                        :kind="slide.content.chartType ?? 'bar'"
                        :ink="appearance?.ink ?? '#e6e9ef'"
                        :accent="appearance?.accent ?? '#58a6ff'"
                    />
                    <div v-else class="sf-bars">
                        <span v-for="(row, at) in (slide.content.series ?? []).slice(0, 5)" :key="at" />
                    </div>
                </template>

                <template v-else-if="slide.layout === 'image'">
                    <div class="sf-image">
                        <img
                            v-if="slide.content.mediaUrl"
                            class="sf-image-file"
                            :src="slide.content.mediaUrl"
                            :alt="slide.content.mediaAlt ?? ''"
                        >
                        <span v-else class="sf-image-mark" />
                        <p
                            v-if="!compact && slide.content.caption && slide.content.captionOver === true"
                            class="sf-caption sf-caption-over"
                            v-html="emphasis(slide.content.caption)"
                        />
                    </div>
                    <p
                        v-if="!compact && slide.content.caption && slide.content.captionOver !== true"
                        class="sf-caption"
                        v-html="emphasis(slide.content.caption)"
                    />
                </template>
            </div>

            <!-- Out of the flow: the strip carries a logo and a number, not
                 content, and a footer that pushes the text up would make a
                 numbered slide smaller than its neighbours. -->
            <div v-if="hasFooter" class="sf-footer">
                <img v-if="showsLogo" class="sf-logo" :src="appearance.logoUrl" :alt="appearance.logoAlt ?? ''">
                <span v-else />
                <span v-if="footerText" class="sf-footer-text">{{ footerText }}</span>
                <span v-else />
                <span v-if="showsNumber" class="sf-number">{{ index }}</span>
                <span v-else />
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Container queries rather than a viewport breakpoint: the same frame is a
   160px thumbnail and a 700px preview on the same screen, so what the type has
   to follow is its own box, not the window. */
/**
 * The 16/9 ratio through padding, not through `aspect-ratio`.
 *
 * `aspect-ratio` gives way as soon as the parent decides the height some other
 * way, and the thumbnail went square again without anything flagging it:
 * neither `min-height: 0` nor leaving the flex context was enough.
 * `padding-top: 56.25%` always resolves against the width, whatever the
 * context, and that is the one thing here that must hold everywhere: a preview
 * that does not have the shape of the slide is a preview that lies.
 */
.slide-ratio {
    /* The query container is this box: its width is decided by the column
       (246 px) or by the page (768 px), so `cqw` resolves to something known
       here. Carried by the frame itself, which is positioned, the unit
       resolved against a width the browser had not settled yet, and the
       type shrank to nothing. */
    container-type: inline-size;
    position: relative;
    width: 100%;
    padding-top: 56.25%;
}

/**
 * The fallback values are the ones from before themes.
 *
 * A frame drawn outside a deck (a demo thumbnail, a component test) has no
 * appearance to receive, and must stay readable. The custom properties say so
 * once here rather than at every use.
 */
.slide-frame {
    --slide-bg: var(--color-surface-2, #161b22);
    --slide-ink: inherit;
    --slide-accent: currentColor;
    --slide-heading: inherit;
    --slide-body: inherit;

    --frame-pad: 6cqw;

    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    padding: var(--frame-pad);
    background: var(--slide-bg);
    color: var(--slide-ink);
    font-family: var(--slide-body);
    border: 1px solid var(--color-line, #30363d);
    border-radius: 0.5rem;
    /* Clipped rather than stretched: an overfilled slide overflows on the wall
       too, and a preview that grows to show everything is a preview that lies. */
    overflow: hidden;
}

/**
 * The fit factor, and centring that throws nothing out.
 *
 * `safe center` centres while the content fits and switches to top alignment
 * as soon as it overflows. Without it, a centred column that runs over spills
 * out at both ends: the title went above the frame, the last line below, and
 * `overflow: hidden` cut both without a word.
 *
 * This is the belt; `useSlideFit` is the braces, and makes sure the case only
 * comes up with really too many words.
 */
.slide-stage {
    --fit: 1;

    position: relative;
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
    justify-content: safe center;
    gap: calc(2cqw * var(--fit));
}

/* Under the content and under the footer, in their own layer: set as a
   `background-image` on the frame, the image would have been clipped by the
   padding and the veil would have had to be a second image. */
.sf-backdrop { position: absolute; inset: 0; overflow: hidden; }
/* The paint of a free slide, under everything else: a colour or a gradient
   chosen for it, over the deck background. */
.sf-fill { position: absolute; inset: 0; }
/* `cover` here, unlike the image slide: a background is scenery, and a strip
   of colour on the side of scenery shows more than the corner it loses. */
.sf-backdrop-file { width: 100%; height: 100%; object-fit: cover; }
.sf-backdrop-veil { position: absolute; inset: 0; background: var(--slide-bg); }

/* The tracking shot. Twenty seconds for one percent of movement: to the eye
   it is not motion, it is an image that breathes. Cut off when the person
   asked for reduced motion. */
@keyframes sf-drift {
    from { transform: scale(1.06) translate3d(-0.6%, -0.4%, 0); }
    to { transform: scale(1.12) translate3d(0.6%, 0.4%, 0); }
}

.sf-backdrop-file.is-drifting {
    animation: sf-drift 24s ease-in-out infinite alternate;
    will-change: transform;
}

@media (prefers-reduced-motion: reduce) {
    .sf-backdrop-file.is-drifting { animation: none; }
}

/* A line not revealed yet keeps its place and stays invisible. `visibility`
   and not `display`: the frame measures its own text and shrinks it to fit,
   so a list that grew line by line would resize every word on the slide at
   each key press. */
.is-held { visibility: hidden; }
.sf-agenda li.is-held,
.sf-end-lines > .is-held { visibility: hidden; }

@media (prefers-reduced-motion: no-preference) {
    .sf-list > li,
    .sf-card,
    .sf-step,
    .sf-figure { transition: opacity 180ms ease; }

    .is-held { opacity: 0; }
}

/* Paper does not move. The class is already only set by the player, but a
   printed sheet that caught an image mid-drift is a mistake nobody would see
   before receiving the PDF. */
@media print {
    .sf-backdrop-file.is-drifting { animation: none; }

    /* The deck prints in its colours, full stop.
     *
     * Without this line, the background only comes out if the reader ticked
     * "print backgrounds", so the result depends on a checkbox in someone
     * else's browser: nobody knows what they will get, the author first of
     * all. A deck PDF is a file you send, not a sheet you print, and a deck
     * that arrives grey on white is a broken deliverable. Whoever wants to
     * save ink has "greyscale" in their own print dialog, which they already
     * know. */
    .slide-frame {
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
    }
}

/* The photo treatment. The blur is scaled up a touch: a blurred image in its
   frame shows its sharp edges, which is worse than no blur. */
.sf-backdrop[data-treatment="blur"] .sf-backdrop-file { filter: blur(1.2cqw); transform: scale(1.06); }
.sf-backdrop[data-treatment="mono"] .sf-backdrop-file { filter: grayscale(1) contrast(1.06); }
.sf-backdrop[data-treatment="duotone"] .sf-backdrop-file { filter: grayscale(1) contrast(1.1); }

/* The layer that adds what a filter cannot do: a colour, or noise. Between
   the photo and the veil, so that lowering the veil also uncovers the tint
   rather than leaving it floating on top. */
.sf-backdrop-film { position: absolute; inset: 0; }

.sf-backdrop[data-treatment="duotone"] .sf-backdrop-film {
    background: var(--slide-accent);
    mix-blend-mode: color;
    opacity: 0.85;
}

.sf-backdrop[data-treatment="grain"] .sf-backdrop-film {
    background-image: repeating-radial-gradient(
        circle at 0 0,
        rgb(255 255 255 / 16%) 0 0.18cqw,
        transparent 0.18cqw 0.55cqw
    );
    mix-blend-mode: overlay;
}

/* The directional veil. To make the text readable, the flat veil has to dull
   the whole photo; this one only comes down on the side where the words are,
   and the inline opacity multiplies it as it multiplied the flat one. */
.sf-backdrop[data-veil="bottom"] .sf-backdrop-veil {
    background: linear-gradient(0deg, var(--slide-bg) 6%, color-mix(in srgb, var(--slide-bg) 58%, transparent) 46%, transparent 82%);
}

.sf-backdrop[data-veil="top"] .sf-backdrop-veil {
    background: linear-gradient(180deg, var(--slide-bg) 6%, color-mix(in srgb, var(--slide-bg) 58%, transparent) 46%, transparent 82%);
}

/* The vignette brings the eye back to the centre. Above the scenery and under
   the wash, as a correction of the photo and not as a colour of the deck. */
.sf-vignette {
    position: absolute;
    inset: 0;
    pointer-events: none;
    /* Towards the deck's ink and not towards black, and subtle.
       A black vignette on a light theme does not make a dark corner, it
       makes a grey slide: that is already why the scenery veil tints towards
       the background rather than towards black, and I had forgotten it when
       writing this one. */
    background: radial-gradient(
        82% 92% at 50% 50%,
        transparent 58%,
        color-mix(in srgb, var(--slide-ink) 26%, transparent) 100%
    );
}

/* The image container, in the two templates that have one. */
.slide-frame[data-media-frame="line"] :is(.sf-image, .sf-beside-media) {
    border: 0.6cqw solid var(--slide-accent);
}

.slide-frame[data-media-frame="shadow"] :is(.sf-image, .sf-beside-media) {
    box-shadow: 0 2cqw 4.5cqw rgb(0 0 0 / 30%);
}

/* The caption set in the corner of the image rather than under it: the photo
   gets back the two lines the caption took from it. Its own veil, because a
   caption does not choose what is behind it. */
.sf-caption-over {
    position: absolute;
    left: 2cqw;
    bottom: 2cqw;
    max-width: calc(100% - 4cqw);
    padding: 1cqw 1.8cqw;
    border-radius: 0.6cqw;
    color: #fff;
    background: rgb(0 0 0 / 45%);
    opacity: 1;
}

/* The frame margin. `normal` has no rule: it is the value `.slide-frame`
   itself carries, so a deck that never opened the panel draws exactly as
   before. */
.slide-frame[data-margins="tight"] { --frame-pad: 3cqw; }
.slide-frame[data-margins="wide"] { --frame-pad: 11cqw; }

/* Above everything, content included: a hairline set under the text would go
   behind a background image and no longer show. */
.sf-hairline {
    position: absolute;
    inset: 3cqw;
    z-index: 5;
    border: 0.25cqw solid color-mix(in srgb, var(--slide-accent) 55%, transparent);
    border-radius: 0.2cqw;
    pointer-events: none;
}

/* Anchoring, alignment and width. No rule for the values that were already
   the module's: the attribute is not even emitted. */
.slide-frame[data-anchor="top"] .slide-stage { justify-content: safe flex-start; }
.slide-frame[data-anchor="bottom"] .slide-stage { justify-content: safe flex-end; }

/* Alignment only touches the text, never the width of the boxes.
   An `align-items` other than `stretch` sizes each child on its content: an
   image, whose file is absolutely positioned, then measures nothing at all
   and disappears from the slide. The text is therefore aligned with
   `text-align`, and only the blocks that carry nothing but text shrink. */
.slide-frame[data-align="left"] .slide-stage { text-align: left; }
.slide-frame[data-align="center"] .slide-stage { text-align: center; }
.slide-frame[data-align="right"] .slide-stage { text-align: right; }

.slide-frame[data-align="center"] .slide-stage > :is(p, ul, ol) { align-self: center; }
.slide-frame[data-align="right"] .slide-stage > :is(p, ul, ol) { align-self: flex-end; }

/* The explicit alignment wins over the centring the section template has
   hard-coded, otherwise the choice would be ignored on the one template where
   it shows the most. */
.slide-frame[data-align="left"] .sf-section { text-align: left; }
.slide-frame[data-align="right"] .sf-section { text-align: right; }

/* The width follows the alignment: a narrow column aligned right sits on the
   right, it does not stay centred with a gap on one side. */
.slide-frame[data-measure="two_thirds"] .slide-stage > * { max-width: 66%; }
.slide-frame[data-measure="half"] .slide-stage > * { max-width: 50%; }
.slide-frame[data-align="center"][data-measure] .slide-stage > * { margin-inline: auto; }

/* Under the scenery: a pattern is a texture of the background, and a slide
   whose background is a photo shows none. Sizes are in `cqw` like the rest,
   so that the grain of a thumbnail is the grain of the wall. */
.sf-pattern { position: absolute; inset: 0; pointer-events: none; }

.slide-frame[data-pattern="dots"] .sf-pattern {
    background-image: radial-gradient(
        color-mix(in srgb, var(--slide-accent) 30%, transparent) 0.45cqw,
        transparent 0.45cqw
    );
    background-size: 3.5cqw 3.5cqw;
}

.slide-frame[data-pattern="grid"] .sf-pattern {
    background-image:
        linear-gradient(to right, color-mix(in srgb, var(--slide-accent) 18%, transparent) 0.12cqw, transparent 0.12cqw),
        linear-gradient(to bottom, color-mix(in srgb, var(--slide-accent) 18%, transparent) 0.12cqw, transparent 0.12cqw);
    background-size: 5cqw 5cqw;
}

.slide-frame[data-pattern="diagonals"] .sf-pattern {
    background-image: repeating-linear-gradient(
        45deg,
        color-mix(in srgb, var(--slide-accent) 16%, transparent) 0 0.35cqw,
        transparent 0.35cqw 2.6cqw
    );
}

/* The image shape, set on the container and not on the file: the container
   carries the clipping and the placeholder background, and a missing image
   must keep the shape the slide chose. */
.slide-frame[data-shape="round"] :is(.sf-image, .sf-beside-media) { border-radius: 3cqw; }

/* The top radius in percent, the bottom one in a fixed unit: an arch whose
   feet round off with the width is no longer an arch, it is a capsule. */
.slide-frame[data-shape="arch"] :is(.sf-image, .sf-beside-media) {
    border-radius: 50% 50% 0.25rem 0.25rem / 30% 30% 0.25rem 0.25rem;
}

/* The circle cannot make do with a radius: the box is wider than it is tall
   and would turn it into an ellipse. So it is brought back to a square,
   centred in the width it leaves. */
.slide-frame[data-shape="circle"] :is(.sf-image, .sf-beside-media) {
    align-self: center;
    justify-self: center;
    width: auto;
    max-width: 100%;
    aspect-ratio: 1;
    border-radius: 9999px;
}

/* Above the scenery and under the content: the wash tints the photo too,
   otherwise a slide with an image background would lose the gradient the
   whole deck carries. The accent is mixed with transparent rather than with
   the background, so that the same declaration holds on a flat colour as on
   an image. */
.sf-wash { position: absolute; inset: 0; pointer-events: none; }

.slide-frame[data-gradient="top"] .sf-wash {
    background: linear-gradient(
        180deg,
        color-mix(in srgb, var(--slide-accent) 42%, transparent),
        transparent 64%
    );
}

.slide-frame[data-gradient="bottom"] .sf-wash {
    background: linear-gradient(
        0deg,
        color-mix(in srgb, var(--slide-accent) 42%, transparent),
        transparent 64%
    );
}

.slide-frame[data-gradient="corner"] .sf-wash {
    background: linear-gradient(
        135deg,
        color-mix(in srgb, var(--slide-accent) 38%, transparent),
        transparent 58%
    );
}

.slide-frame[data-gradient="duo"] .sf-wash {
    background: linear-gradient(
        140deg,
        color-mix(in srgb, var(--slide-accent) 52%, transparent),
        color-mix(in srgb, var(--slide-ink) 26%, transparent) 78%
    );
}

.slide-frame[data-gradient="halo"] .sf-wash {
    background: radial-gradient(
        80% 95% at 50% 42%,
        color-mix(in srgb, var(--slide-accent) 34%, transparent),
        transparent 72%
    );
}

.sf-kicker {
    margin: 0;
    font-family: var(--slide-heading);
    font-size: calc(2.8cqw * var(--fit));
    font-weight: 600;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: var(--slide-accent);
}

.sf-title { margin: 0; font-family: var(--slide-heading); font-size: calc(8cqw * var(--fit) * var(--title-scale, 1)); font-weight: 600; line-height: 1.1; }
.sf-subtitle { margin: 0; font-size: calc(4cqw * var(--fit)); opacity: 0.7; }
.sf-section { position: relative; margin: 0; font-family: var(--slide-heading); font-size: calc(7cqw * var(--fit) * var(--title-scale, 1)); font-weight: 600; text-align: center; }
.sf-heading { margin: 0; font-family: var(--slide-heading); font-size: calc(6cqw * var(--fit) * var(--title-scale, 1)); font-weight: 600; }
/* `list-style` restored explicitly: the Tailwind reset removes the markers
   from every list, and a bulleted list without bullets reads like a broken
   paragraph. */
.sf-list { margin: 0; padding-left: 5cqw; font-size: calc(4cqw * var(--fit)); line-height: 1.5; list-style: disc outside; }
.sf-list li { margin-bottom: 1cqw; }
/* The accent colour is spent on the markers and nowhere else in a list: a
   coloured bullet stands out, a coloured sentence reads badly. */
.sf-list li::marker { color: var(--slide-accent); }
.sf-quote { margin: 0; font-size: calc(6cqw * var(--fit)); font-style: italic; line-height: 1.3; }
.sf-attribution { margin: 0; font-size: calc(3.5cqw * var(--fit)); opacity: 0.7; }
.sf-caption { margin: 0; font-size: calc(3.5cqw * var(--fit)); opacity: 0.7; }

/* A heading above dense content: smaller than on a bullet slide, otherwise it
   takes a third of the height left for the table. */
.sf-heading-small { font-size: calc(4.8cqw * var(--fit) * var(--title-scale, 1)); }

.sf-stat {
    margin: 0;
    font-family: var(--slide-heading);
    font-size: calc(20cqw * var(--fit));
    font-weight: 700;
    line-height: 0.85;
    letter-spacing: -0.03em;
    color: var(--slide-accent);
    font-variant-numeric: tabular-nums;
}

.sf-stat-label { margin: 0; font-size: calc(4cqw * var(--fit)); line-height: 1.35; max-width: 70%; opacity: 0.85; }

.sf-beside { flex: 1; min-height: 0; display: grid; grid-template-columns: 1.1fr 1fr; gap: 4cqw; align-items: center; }
/* The image moves to the right by reversing the order rather than the
   columns: the text stays before the image in the document, so in a screen
   reader's reading order, whatever side was chosen for the eye. */
.sf-beside.is-right .sf-beside-media { order: 2; }
/* Same arrangement as `.sf-image`, for the same reason. */
.sf-beside-media { position: relative; height: 100%; min-height: 0; overflow: hidden; border-radius: 0.25rem; background: color-mix(in srgb, currentColor 10%, transparent); }
.sf-beside-text { margin: 0; font-size: calc(3.6cqw * var(--fit)); line-height: 1.5; }

.sf-cards { display: grid; grid-template-columns: repeat(var(--cards, 3), 1fr); gap: 2.4cqw; }
.sf-card {
    display: flex;
    flex-direction: column;
    gap: 1cqw;
    padding: 2.8cqw;
    border-radius: 0.25rem;
    background: color-mix(in srgb, currentColor 8%, transparent);
    border-top: 0.5cqw solid var(--slide-accent);
}
.sf-card-head { font-family: var(--slide-heading); font-size: calc(3.4cqw * var(--fit)); font-weight: 600; line-height: 1.2; }
.sf-card-body { font-size: calc(2.6cqw * var(--fit)); line-height: 1.35; opacity: 0.72; }

.sf-steps { display: grid; grid-auto-flow: column; grid-auto-columns: 1fr; gap: 2cqw; margin: 0; padding: 0; list-style: none; }
.sf-step { display: flex; flex-direction: column; gap: 1.2cqw; }
/* The line starts at the dot and runs to the right: it is the timeline, and
   it stops at the last step rather than leaving the frame. */
.sf-step-mark { position: relative; height: 2.4cqw; border-radius: 50%; width: 2.4cqw; background: var(--slide-accent); }
.sf-step-mark::after { content: ""; position: absolute; top: 50%; left: 2.4cqw; width: 100cqw; height: 0.3cqw; background: currentColor; opacity: 0.22; }
.sf-step:last-child .sf-step-mark::after { display: none; }
.sf-step-head { font-family: var(--slide-heading); font-size: calc(3cqw * var(--fit)); font-weight: 600; line-height: 1.2; }
.sf-step-body { font-size: calc(2.5cqw * var(--fit)); line-height: 1.3; opacity: 0.7; }

.sf-table { width: 100%; border-collapse: collapse; font-size: calc(3cqw * var(--fit)); }
.sf-table th { text-align: left; font-family: var(--slide-heading); font-weight: 600; padding-bottom: 1.2cqw; border-bottom: 0.3cqw solid var(--slide-accent); }
.sf-table td { padding: 1.2cqw 0; border-bottom: 1px solid color-mix(in srgb, currentColor 15%, transparent); }
.sf-table tr:last-child td { border-bottom: 0; }
.sf-table th + th, .sf-table td + td { padding-left: 3cqw; }

/* `code` in a slide: a tint of the text colour rather than a grey box, which
   on a light theme becomes the only dark spot on the slide and draws the eye
   more than what it marks. */
.slide-frame :deep(code) {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 0.9em;
    padding: 0.1em 0.3em;
    border-radius: 0.2em;
    background: color-mix(in srgb, currentColor 12%, transparent);
}

/**
 * Bold in a heading, through colour as much as through weight.
 *
 * A heading is already at 600, and in a monospace face the step up to 700 is
 * invisible: the highlighted word was not highlighted. The accent says it in
 * every pair. In body text, which starts at 400, weight is enough and one more
 * colour would be a second thing to read.
 */
.slide-frame :deep(strong) { font-weight: 700; }

/* The badge, and the icon. Both in the accent, both discreet: they punctuate
   a card, they do not replace it. */
.sf-badge {
    align-self: flex-start;
    font-size: calc(2.2cqw * var(--fit));
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    padding: 0.7cqw 1.6cqw;
    border-radius: 9999px;
    background: color-mix(in srgb, var(--slide-accent) 22%, transparent);
    color: var(--slide-accent);
}

.sf-icon { width: 6cqw; height: 6cqw; color: var(--slide-accent); }
.sf-step-icon { width: 4cqw; height: 4cqw; color: var(--slide-accent); flex: 0 0 auto; }

/* The gauge: the share a figure represents, drawn under it. */
.sf-gauge { display: block; height: 1.4cqw; border-radius: 9999px; background: color-mix(in srgb, currentColor 14%, transparent); overflow: hidden; }
.sf-gauge > i { display: block; height: 100%; background: var(--slide-accent); }

/* The brands, brought to the same optical height rather than the same width:
   height is what an eye compares, and `contain` keeps each one whole in its
   cell. */
.sf-logos { flex: 1; min-height: 0; display: grid; grid-template-columns: repeat(var(--logos, 4), 1fr); gap: 4cqw; align-items: center; justify-items: center; }
/* `sf-logos-cell` and not `sf-logo`: the footer already has a class of that
   name for the deck's brand, and the two rules stepped on each other. */
.sf-logos-cell { display: block; width: 100%; height: 8cqw; }
.sf-logos-cell img { width: 100%; height: 100%; object-fit: contain; }

/* The mosaic. The arrangements are declared the way templates are: with two
   images one column each, with three one large and two small, beyond that a
   regular grid. Each document's focal point is respected, otherwise a crop
   would cut through faces. */
.sf-mosaic { flex: 1; min-height: 0; display: grid; gap: 1.5cqw; grid-template-columns: repeat(2, 1fr); grid-auto-rows: 1fr; }
.sf-mosaic[data-count="1"] { grid-template-columns: 1fr; }
.sf-mosaic[data-count="3"] { grid-template-columns: 1.4fr 1fr; }
.sf-mosaic[data-count="3"] .sf-mosaic-cell:first-child { grid-row: span 2; }
.sf-mosaic[data-count="5"],
.sf-mosaic[data-count="6"] { grid-template-columns: repeat(3, 1fr); }
.sf-mosaic[data-count="7"],
.sf-mosaic[data-count="8"] { grid-template-columns: repeat(4, 1fr); }
.sf-mosaic-cell { position: relative; overflow: hidden; border-radius: 0.25rem; background: color-mix(in srgb, currentColor 10%, transparent); }
.sf-mosaic-cell img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }

/* The agenda. The rank in monospace and in the accent, the current line alone
   at full ink: contrast says where we are, not a bullet. */
.sf-agenda { flex: 1; min-height: 0; margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; justify-content: center; gap: 1.8cqw; }
.sf-agenda li { display: flex; align-items: baseline; gap: 3cqw; font-size: calc(4.2cqw * var(--fit)); opacity: 0.45; }
.sf-agenda li.is-current { opacity: 1; font-weight: 600; }
.sf-agenda-rank { font-family: var(--slide-body); font-size: calc(2.8cqw * var(--fit)); font-variant-numeric: tabular-nums; color: var(--slide-accent); }

/* The testimonial. The face round and small: a quote stays a quote, the photo
   goes with it instead of competing with it. */
.sf-portrait { flex: 1; min-height: 0; display: flex; align-items: center; gap: 5cqw; }
/* Always cropped, never letterboxed: a round portrait whose image is contained
   leaves two background bands inside the circle, which nobody chooses. So the
   template offers no framing option, it has one. */
.sf-portrait-face { flex: 0 0 auto; position: relative; width: 22cqw; aspect-ratio: 1; border-radius: 9999px; overflow: hidden; background: color-mix(in srgb, currentColor 10%, transparent); }
.sf-portrait-face .sf-image-file { object-fit: cover; }
.sf-portrait-words { min-width: 0; display: flex; flex-direction: column; gap: 2cqw; }
.sf-portrait-quote { margin: 0; font-family: var(--slide-heading); font-size: calc(4.6cqw * var(--fit)); line-height: 1.3; font-style: italic; }
.sf-portrait-who { margin: 0; display: flex; flex-direction: column; font-size: calc(2.8cqw * var(--fit)); }
.sf-portrait-role { opacity: 0.7; }

/* The colour band. Under the content and under the deck's scenery, but above
   the background: it is a shape laid on the slide, not a tint of the floor. */
.sf-band { position: absolute; background: var(--slide-accent); pointer-events: none; }

/* In container units on both sides: the band was measured on the frame and
   the text offset on the stage, which already has its margins cut off, so the
   text never lined up where the band ended. */
.slide-frame[data-band="left"] .sf-band { inset: 0 auto 0 0; width: 32cqw; }
.slide-frame[data-band="bottom"] .sf-band { inset: auto 0 0 0; height: 18cqh; }
.slide-frame[data-band="edge"] .sf-band { inset: 0 auto 0 0; width: 2.5cqw; }

/* The content moves aside so as not to go under it. The thin edge needs no
   more than the margin the frame already keeps. */
.slide-frame[data-band="left"] .slide-stage { padding-left: calc(32cqw - var(--frame-pad) + 4cqw); }
.slide-frame[data-band="bottom"] .slide-stage { padding-bottom: calc(18cqh - var(--frame-pad) + 3cqw); }

/* The bleed: the image leaves the frame margin on the side where it sits, and
   reaches the edge. The margin is read rather than copied, otherwise a deck
   with wide margins would leave a band of background between image and edge. */
.slide-frame[data-bleed="on"] .sf-beside-media {
    margin-left: calc(var(--frame-pad) * -1);
    border-radius: 0;
}

.slide-frame[data-bleed="on"] .sf-beside.is-right .sf-beside-media {
    margin-left: 0;
    margin-right: calc(var(--frame-pad) * -1);
}

/* The deck's rules: between the two columns, and under the kicker. */
.slide-frame[data-rules="on"] .sf-columns > :last-child {
    border-left: 0.2cqw solid color-mix(in srgb, currentColor 22%, transparent);
    padding-left: 4cqw;
}

.slide-frame[data-rules="on"] .sf-kicker {
    padding-bottom: 1.4cqw;
    border-bottom: 0.2cqw solid color-mix(in srgb, var(--slide-accent) 45%, transparent);
}

/* Two columns that answer each other. The rule between them states the
   contrast the `split` template only hinted at. */
/* `align-items: start` lines the two headers up with each other,
   `align-content` centres the block in the remaining height: without the
   second, a comparison of two short sentences sticks to the top of a frame
   left three quarters empty. */
.sf-compare { flex: 1; min-height: 0; display: grid; grid-template-columns: 1fr 1fr; grid-auto-rows: min-content; align-content: center; gap: 5cqw; align-items: start; }
.sf-compare-side { display: flex; flex-direction: column; gap: 1.6cqw; min-width: 0; }
.sf-compare-side.is-second { border-left: 0.25cqw solid color-mix(in srgb, currentColor 22%, transparent); padding-left: 5cqw; }
.sf-compare-side p { margin: 0; font-size: calc(3.4cqw * var(--fit)); line-height: 1.45; }

.sf-compare-head {
    font-family: var(--slide-heading);
    font-size: calc(2.9cqw * var(--fit));
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--slide-accent);
}

/* The figures, side by side. The value carries the accent and the label stays
   in ink: the reverse would make the label read before the figure. */
.sf-figures { flex: 1; min-height: 0; display: grid; grid-template-columns: repeat(var(--figures, 3), 1fr); gap: 4cqw; align-items: center; }
.sf-figure { display: flex; flex-direction: column; gap: 1cqw; min-width: 0; }

.sf-figure-value {
    font-family: var(--slide-heading);
    font-size: calc(9cqw * var(--fit));
    font-weight: 700;
    line-height: 1;
    letter-spacing: -0.03em;
    color: var(--slide-accent);
}

.sf-figure-label { font-size: calc(2.9cqw * var(--fit)); line-height: 1.35; opacity: 0.78; }

/* The last slide. The contact lines under the word, tight, without bullets. */
.sf-end-lines { display: flex; flex-direction: column; gap: 0.8cqw; font-size: calc(3.2cqw * var(--fit)); opacity: 0.8; }

/* Heading case, decided for the whole deck. All three selectors and not just
   one: they are three different classes depending on the template, and an
   upper-case title on the cover only would not be a deck decision. */
.slide-frame[data-title-case="upper"] :is(.sf-title, .sf-heading, .sf-section) {
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

/* The bullet shape. The colour is already the accent's. */
.slide-frame[data-bullets="dash"] .sf-list { list-style-type: "–  "; }
.slide-frame[data-bullets="arrow"] .sf-list { list-style-type: "→  "; }
.slide-frame[data-bullets="check"] .sf-list { list-style-type: "✓  "; }
.slide-frame[data-bullets="number"] .sf-list { list-style: decimal outside; }

/* The section number, behind the title and outside the space calculation: in
   the flow, it would push the title and make `useSlideFit` shrink the text to
   make room for a decoration. */
.sf-ghost {
    position: absolute;
    right: 0;
    bottom: -4cqw;
    font-family: var(--slide-heading);
    font-size: 42cqw;
    font-weight: 700;
    line-height: 0.8;
    letter-spacing: -0.06em;
    color: color-mix(in srgb, var(--slide-accent) 22%, transparent);
    pointer-events: none;
}

/* The accented word. No background, unlike what `mark` does by default in a
   browser: on a slide, a yellow highlight would be the only colour in the deck
   that nobody chose. */
.slide-frame :deep(mark) {
    background: none;
    color: var(--slide-accent);
}
.sf-title :deep(strong),
.sf-section :deep(strong),
.sf-heading :deep(strong) { color: var(--slide-accent); }

.sf-columns { display: grid; grid-template-columns: 1fr 1fr; gap: 4cqw; font-size: calc(3.6cqw * var(--fit)); }
.sf-columns p { margin: 0; }

/**
 * The image fills its box, and the box decides.
 *
 * In a grid with `place-items: center`, the image's `height: 100%` resolved
 * against a row whose height was decided by the image: the browser broke the
 * cycle by falling back to the natural size, a 1280 px square image was drawn
 * 815 px tall in a 293 px box, and `overflow: hidden` clipped it top and
 * bottom. A `contain` image getting clipped is precisely what `contain`
 * promises not to do.
 *
 * Absolutely positioned against a positioned box, there is no cycle any more:
 * the box has its height before the image asks for its own.
 */
.sf-image { position: relative; flex: 1; min-height: 0; overflow: hidden; border-radius: 0.25rem; background: color-mix(in srgb, currentColor 10%, transparent); }
.sf-image-mark { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); }
.sf-image-mark { width: 12cqw; height: 12cqw; border-radius: 9999px; background: currentColor; opacity: 0.25; }
/* `contain` by default: a screenshot cropped to fill the frame loses exactly
   the corner you wanted to show. A photo, though, often gains from filling,
   hence the per-slide setting - and the focal point that goes with it. */
.sf-image-file {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: var(--media-fit, contain);
    object-position: var(--media-focus, 50% 50%);
}

/* The stand-in for a chart in a thumbnail: fixed heights, because measuring
   them would mean reading the data for three pixels of height. */
.sf-bars { display: flex; align-items: flex-end; gap: 2cqw; height: 28cqw; }
.sf-bars span { flex: 1; background: currentColor; opacity: 0.25; border-radius: 1px 1px 0 0; height: 45%; }
.sf-bars span:nth-child(2) { height: 75%; }
.sf-bars span:nth-child(3) { height: 100%; }
.sf-bars span:nth-child(4) { height: 60%; }
.sf-bars span:nth-child(5) { height: 35%; }

/* The thumbnail's stand-in for body text: grey bars say "there are four
   bullets here" without pretending 3px of type is readable. */
.sf-lines { display: flex; flex-direction: column; gap: 2cqw; }
.sf-lines span { height: 2cqw; border-radius: 9999px; background: currentColor; opacity: 0.2; }
.sf-lines span:nth-child(2) { width: 80%; }
.sf-lines span:nth-child(3) { width: 65%; }
.sf-lines span:nth-child(4) { width: 72%; }

/* Three columns and not a `space-between`: the footer text stays centred on
   the slide even when there is no logo or number on either side. */
.sf-footer {
    /* Positioned, like the stage: a background layer is too, and an
       unpositioned element goes under it whatever the DOM order says. */
    position: relative;
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    align-items: center;
    gap: 2cqw;
    padding-top: 2cqw;
    font-size: 2.4cqw;
    opacity: 0.55;
}

.sf-footer-text { text-align: center; }
.sf-number { justify-self: end; font-variant-numeric: tabular-nums; }
.sf-logo { justify-self: start; max-height: 4cqw; max-width: 22cqw; object-fit: contain; }

.is-compact { padding: 7cqw; }
.is-compact .slide-stage { gap: calc(1.5cqw * var(--fit)); }
</style>
