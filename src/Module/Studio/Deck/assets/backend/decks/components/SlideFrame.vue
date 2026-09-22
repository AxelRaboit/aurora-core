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
import {
    ArrowRight,
    Award,
    Ban,
    Check,
    Clock,
    Euro,
    Eye,
    Flag,
    Lightbulb,
    Lock,
    Rocket,
    Settings,
    TrendingUp,
    TriangleAlert,
    Users,
    Zap,
} from "lucide-vue-next";
import { cells, decorated, headed, measured } from "../cells.js";
import { useSlideFit } from "../composables/useSlideFit.js";
import { emphasis } from "../emphasis.js";
import SlideChart from "./SlideChart.vue";

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

/**
 * The sixteen icons a slide may name, and not the whole of Lucide.
 *
 * **Declared like everything else in this module.** The library holds well
 * over a thousand, and importing by name at render would mean shipping all of
 * them to every reader of a public share link for the two a deck actually
 * uses. Sixteen cover what a deck argues about: time, money, people, risk,
 * speed, a rule, a goal.
 *
 * A name nothing matches draws nothing, which is the same answer the frame
 * gives to a layout value it has no rule for.
 */
const ICONS = {
    check: Check,
    arrow: ArrowRight,
    clock: Clock,
    euro: Euro,
    users: Users,
    warning: TriangleAlert,
    ban: Ban,
    lock: Lock,
    eye: Eye,
    rocket: Rocket,
    zap: Zap,
    trend: TrendingUp,
    idea: Lightbulb,
    award: Award,
    flag: Flag,
    settings: Settings,
};

const iconFor = (name) => ICONS[name] ?? null;

const skin = computed(() => {
    const look = props.appearance;

    if (!look) return {};

    return {
        // The accent ground borrows the deck's background for its text: it is
        // the one tone guaranteed to sit at a distance from the accent, since
        // the palette was picked so the ink reads on it.
        "--slide-bg": { inverted: look.ink, accent: look.accent }[ground.value] ?? look.background,
        "--slide-ink": "normal" === ground.value ? look.ink : look.background,
        "--slide-accent": look.accent,
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

            <span v-if="vignette" class="sf-vignette" aria-hidden="true" />

            <span v-if="gradient !== 'none'" class="sf-wash" aria-hidden="true" />

            <span v-if="hairline" class="sf-hairline" aria-hidden="true" />

            <span v-if="band !== 'none'" class="sf-band" aria-hidden="true" />

            <div ref="stage" class="slide-stage" :style="{ '--fit': fit }">
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
                        <li v-for="(bullet, at) in slide.content.bullets ?? []" :key="at" v-html="emphasis(bullet)" />
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
                        <div v-for="(figure, at) in (slide.content.figures ?? []).slice(0, 4)" :key="at" class="sf-figure">
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
                        <span v-for="(picture, at) in slide.content.mediaPictures ?? []" :key="at" class="sf-logo">
                            <img :src="picture.url" :alt="picture.alt">
                        </span>
                    </div>
                </template>

                <template v-else-if="slide.layout === 'mosaic'">
                    <p v-if="slide.content.title" class="sf-heading sf-heading-small" v-html="emphasis(slide.content.title)" />
                    <div class="sf-mosaic" :data-count="Math.min((slide.content.mediaPictures ?? []).length, 8)">
                        <span v-for="(picture, at) in slide.content.mediaPictures ?? []" :key="at" class="sf-mosaic-cell">
                            <img :src="picture.url" :alt="picture.alt" :style="{ objectPosition: picture.focus }">
                        </span>
                    </div>
                </template>

                <template v-else-if="slide.layout === 'agenda'">
                    <p v-if="slide.content.title" class="sf-heading sf-heading-small" v-html="emphasis(slide.content.title)" />
                    <ol class="sf-agenda">
                        <li
                            v-for="(step, at) in slide.content.steps ?? []"
                            :key="at"
                            :class="at + 1 === slide.content.current ? 'is-current' : ''"
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
                        <span v-for="(line, at) in slide.content.lines ?? []" :key="at" v-html="emphasis(line)" />
                    </div>
                </template>

                <template v-else-if="slide.layout === 'cards'">
                    <p v-if="slide.content.title" class="sf-heading sf-heading-small" v-html="emphasis(slide.content.title)" />
                    <div class="sf-cards" :style="{ '--cards': Math.min((slide.content.items ?? []).length || 1, 4) }">
                        <div v-for="(item, at) in slide.content.items ?? []" :key="at" class="sf-card">
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
                        <li v-for="(step, at) in slide.content.steps ?? []" :key="at" class="sf-step">
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
                        <!-- La première ligne est l'en-tête, et c'est une
                             convention du gabarit : un tableau de slide sans
                             en-tête est une grille de chiffres sans légende. -->
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
                    <!-- Pas de canevas dans une vignette de 160 px : une toile
                         Chart.js par slide dans la colonne, c'est une douzaine
                         de contextes de rendu pour des barres hautes de trois
                         pixels. Les barres grises disent qu'il y a un graphique
                         là, ce qui est tout ce qu'une vignette a à dire. -->
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

            <!-- Hors du flux : la bande porte un logo et un numéro, pas du
                 contenu, et un pied de page qui pousse le texte vers le haut
                 ferait d'une slide numérotée une slide plus petite que ses
                 voisines. -->
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
 * Le rapport 16/9 par le remplissage, pas par `aspect-ratio`.
 *
 * `aspect-ratio` cède dès que le parent décide la hauteur autrement, et la
 * vignette redevenait carrée sans que rien ne le signale : ni `min-height: 0`
 * ni une sortie du contexte flex n'y ont suffi. `padding-top: 56.25%` se
 * résout toujours contre la largeur, quel que soit le contexte, et c'est le
 * seul point ici qui doit être vrai partout : un aperçu qui ne fait pas la
 * forme de la slide est un aperçu qui ment.
 */
.slide-ratio {
    /* Le conteneur de requête, c'est cette boîte-ci : sa largeur est décidée
       par la colonne (246 px) ou par la page (768 px), donc `cqw` y résout
       quelque chose de connu. Portée par le cadre lui-même, qui est
       positionné, l'unité se résolvait contre une largeur que le navigateur
       n'avait pas encore arrêtée, et la typographie tombait à rien. */
    container-type: inline-size;
    position: relative;
    width: 100%;
    padding-top: 56.25%;
}

/**
 * Les valeurs de repli sont celles d'avant les thèmes.
 *
 * Un cadre dessiné hors d'un deck - une vignette de démonstration, un test de
 * composant - n'a pas d'apparence à recevoir, et doit rester lisible. Les
 * propriétés personnalisées le disent une fois ici plutôt qu'à chaque usage.
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
    /* Rogné plutôt qu'étiré : une slide trop remplie déborde au mur aussi, et
       un aperçu qui s'agrandit pour tout montrer est un aperçu qui ment. */
    overflow: hidden;
}

/**
 * Le facteur d'ajustement, et le centrage qui ne jette rien dehors.
 *
 * `safe center` centre tant que le contenu tient et bascule en alignement haut
 * dès qu'il déborde. Sans lui, une colonne centrée qui dépasse sort par les
 * deux bouts : le titre partait au-dessus du cadre, la dernière ligne en
 * dessous, et `overflow: hidden` coupait les deux sans rien dire.
 *
 * C'est la ceinture ; `useSlideFit` est les bretelles, et fait que le cas ne
 * se présente qu'avec vraiment trop de mots.
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

/* Sous le contenu et sous le pied de page, dans leur propre couche : posée en
   `background-image` sur le cadre, l'image aurait été rognée par le
   remplissage et le voile aurait eu à être une seconde image. */
.sf-backdrop { position: absolute; inset: 0; overflow: hidden; }
/* `cover` ici, contrairement à la slide image : un fond est un décor, et une
   bande de couleur sur le côté d'un décor se voit plus que le coin qu'il perd. */
.sf-backdrop-file { width: 100%; height: 100%; object-fit: cover; }
.sf-backdrop-veil { position: absolute; inset: 0; background: var(--slide-bg); }

/* Le travelling. Vingt secondes pour un pour cent de déplacement : à l'oeil
   ce n'est pas un mouvement, c'est une image qui respire. Coupé net quand la
   personne a demandé moins d'animation. */
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

/* Le papier ne bouge pas. La classe n'est déjà posée que par le lecteur, mais
   une feuille imprimée qui attraperait une image en plein travelling est une
   erreur que personne ne verrait avant de recevoir le PDF. */
@media print {
    .sf-backdrop-file.is-drifting { animation: none; }

    /* Le deck s'imprime dans ses couleurs, point.
     *
     * Sans cette ligne, le fond ne sort que si le lecteur a coché « imprimer
     * les arrière-plans », donc le rendu dépend d'une case dans le navigateur
     * de quelqu'un d'autre : personne ne sait ce qu'il va obtenir, l'auteur le
     * premier. Un PDF de deck est un fichier qu'on envoie, pas une feuille
     * qu'on imprime, et un deck qui arrive gris sur blanc est un livrable
     * cassé. Celui qui veut économiser son encre a « niveaux de gris » dans sa
     * propre boîte de dialogue, qu'il connaît déjà. */
    .slide-frame {
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
    }
}

/* Le traitement de la photo. Le flou est agrandi d'un poil : une image floutée
   dans son cadre laisse voir ses bords nets, ce qui est pire que pas de flou. */
.sf-backdrop[data-treatment="blur"] .sf-backdrop-file { filter: blur(1.2cqw); transform: scale(1.06); }
.sf-backdrop[data-treatment="mono"] .sf-backdrop-file { filter: grayscale(1) contrast(1.06); }
.sf-backdrop[data-treatment="duotone"] .sf-backdrop-file { filter: grayscale(1) contrast(1.1); }

/* La couche qui ajoute ce qu'un filtre ne sait pas faire : une couleur, ou du
   bruit. Entre la photo et le voile, pour que baisser le voile dévoile aussi
   la teinte plutôt que de la laisser flotter au-dessus. */
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

/* Le voile dirigé. Pour rendre le texte lisible, le voile plat doit ternir
   toute la photo ; celui-ci ne descend que du côté où il y a des mots, et
   l'opacité posée en ligne le multiplie comme elle multipliait l'aplat. */
.sf-backdrop[data-veil="bottom"] .sf-backdrop-veil {
    background: linear-gradient(0deg, var(--slide-bg) 6%, color-mix(in srgb, var(--slide-bg) 58%, transparent) 46%, transparent 82%);
}

.sf-backdrop[data-veil="top"] .sf-backdrop-veil {
    background: linear-gradient(180deg, var(--slide-bg) 6%, color-mix(in srgb, var(--slide-bg) 58%, transparent) 46%, transparent 82%);
}

/* Le vignettage ramène l'oeil au centre. Au-dessus du décor et sous le lavis,
   comme une correction de la photo et non comme une couleur du deck. */
.sf-vignette {
    position: absolute;
    inset: 0;
    pointer-events: none;
    background: radial-gradient(78% 88% at 50% 50%, transparent 46%, rgb(0 0 0 / 42%) 100%);
}

/* Le contenant de l'image, dans les deux gabarits qui en portent un. */
.slide-frame[data-media-frame="line"] :is(.sf-image, .sf-beside-media) {
    border: 0.6cqw solid var(--slide-accent);
}

.slide-frame[data-media-frame="shadow"] :is(.sf-image, .sf-beside-media) {
    box-shadow: 0 2cqw 4.5cqw rgb(0 0 0 / 30%);
}

/* La légende posée dans le coin de l'image plutôt que sous elle : la photo
   reprend les deux lignes qu'elle lui prenait. Son propre voile, parce qu'une
   légende ne choisit pas ce qu'il y a derrière elle. */
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

/* La marge du cadre. `normal` n'a pas de règle : c'est la valeur que porte
   `.slide-frame` lui-même, donc un deck qui n'a jamais ouvert le panneau
   dessine exactement comme avant. */
.slide-frame[data-margins="tight"] { --frame-pad: 3cqw; }
.slide-frame[data-margins="wide"] { --frame-pad: 11cqw; }

/* Au-dessus de tout, y compris du contenu : un filet posé sous le texte
   passerait derrière une image de fond et ne se verrait plus. */
.sf-hairline {
    position: absolute;
    inset: 3cqw;
    z-index: 5;
    border: 0.25cqw solid color-mix(in srgb, var(--slide-accent) 55%, transparent);
    border-radius: 0.2cqw;
    pointer-events: none;
}

/* L'ancrage, l'alignement et la largeur. Aucune règle pour les valeurs qui
   étaient déjà celles du module : l'attribut n'est même pas émis. */
.slide-frame[data-anchor="top"] .slide-stage { justify-content: safe flex-start; }
.slide-frame[data-anchor="bottom"] .slide-stage { justify-content: safe flex-end; }

.slide-frame[data-align="left"] .slide-stage { text-align: left; align-items: flex-start; }
.slide-frame[data-align="center"] .slide-stage { text-align: center; align-items: center; }
.slide-frame[data-align="right"] .slide-stage { text-align: right; align-items: flex-end; }

/* L'alignement explicite l'emporte sur le centrage que le gabarit section
   porte en dur, sinon le choix serait ignoré sur le seul gabarit où il se
   remarque le plus. */
.slide-frame[data-align="left"] .sf-section { text-align: left; }
.slide-frame[data-align="right"] .sf-section { text-align: right; }

/* La largeur suit l'alignement : une colonne étroite alignée à droite se cale
   à droite, elle ne reste pas centrée avec un trou d'un côté. */
.slide-frame[data-measure="two_thirds"] .slide-stage > * { max-width: 66%; }
.slide-frame[data-measure="half"] .slide-stage > * { max-width: 50%; }
.slide-frame[data-align="center"][data-measure] .slide-stage > * { margin-inline: auto; }

/* Sous le décor : un motif est une texture du fond, et une slide dont le fond
   est une photo n'en montre pas. Les tailles sont en `cqw` comme le reste, pour
   que le grain d'une vignette soit celui du mur. */
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

/* La forme de l'image, posée sur le conteneur et non sur le fichier : c'est
   lui qui porte le rognage et le fond de remplacement, et une image absente
   doit garder la forme que la slide a choisie. */
.slide-frame[data-shape="round"] :is(.sf-image, .sf-beside-media) { border-radius: 3cqw; }

/* Le rayon du haut en pourcentage, celui du bas en unité fixe : une arche dont
   les pieds s'arrondissent avec la largeur n'est plus une arche, c'est une
   gélule. */
.slide-frame[data-shape="arch"] :is(.sf-image, .sf-beside-media) {
    border-radius: 50% 50% 0.25rem 0.25rem / 30% 30% 0.25rem 0.25rem;
}

/* Le cercle ne peut pas se contenter d'un rayon : la boîte est plus large que
   haute et en ferait une ellipse. Elle est donc ramenée au carré, centrée sur
   la largeur qu'elle laisse. */
.slide-frame[data-shape="circle"] :is(.sf-image, .sf-beside-media) {
    align-self: center;
    justify-self: center;
    width: auto;
    max-width: 100%;
    aspect-ratio: 1;
    border-radius: 9999px;
}

/* Au-dessus du décor et sous le contenu : le lavis teinte aussi la photo, sans
   quoi une slide à fond image perdrait le dégradé que porte tout le deck.
   L'accent est mélangé à du transparent plutôt qu'au fond, pour que la même
   déclaration tienne sur une couleur plate comme sur une image. */
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
/* `list-style` rétabli explicitement : la réinitialisation de Tailwind retire
   les marqueurs de toutes les listes, et une liste à puces sans puces se lit
   comme un paragraphe coupé. */
.sf-list { margin: 0; padding-left: 5cqw; font-size: calc(4cqw * var(--fit)); line-height: 1.5; list-style: disc outside; }
.sf-list li { margin-bottom: 1cqw; }
/* La couleur d'accent se dépense sur les marqueurs et nulle part ailleurs dans
   une liste : une puce colorée se remarque, une phrase colorée se lit mal. */
.sf-list li::marker { color: var(--slide-accent); }
.sf-quote { margin: 0; font-size: calc(6cqw * var(--fit)); font-style: italic; line-height: 1.3; }
.sf-attribution { margin: 0; font-size: calc(3.5cqw * var(--fit)); opacity: 0.7; }
.sf-caption { margin: 0; font-size: calc(3.5cqw * var(--fit)); opacity: 0.7; }

/* Un titre au-dessus d'un contenu dense : plus petit que celui d'une slide à
   puces, sans quoi il prend le tiers de la hauteur qui reste au tableau. */
.sf-heading-small { font-size: calc(4.8cqw * var(--fit)); }

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
/* L'image passe à droite en inversant l'ordre plutôt que les colonnes : le
   texte reste avant l'image dans le document, donc dans l'ordre de lecture
   d'un lecteur d'écran, quel que soit le côté choisi à l'œil. */
.sf-beside.is-right .sf-beside-media { order: 2; }
/* Même arrangement que `.sf-image`, pour la même raison. */
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
/* Le trait part du point et file vers la droite : c'est la ligne du temps, et
   elle s'arrête à la dernière étape plutôt que de sortir du cadre. */
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

/* `code` dans une slide : une teinte de la couleur du texte plutôt qu'une
   boîte grise, qui sur un thème clair devient la seule tache sombre de la
   slide et attire l'œil plus que ce qu'elle marque. */
.slide-frame :deep(code) {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 0.9em;
    padding: 0.1em 0.3em;
    border-radius: 0.2em;
    background: color-mix(in srgb, currentColor 12%, transparent);
}

/**
 * Le gras, dans un titre, par la couleur autant que par la graisse.
 *
 * Un titre est déjà en 600, et en chasse fixe l'écart jusqu'à 700 est
 * invisible : le mot mis en valeur ne l'était pas. L'accent le dit dans toutes
 * les paires. Dans le texte courant, où l'on part de 400, la graisse suffit et
 * une couleur de plus ferait une deuxième chose à lire.
 */
.slide-frame :deep(strong) { font-weight: 700; }

/* L'étiquette, et l'icône. Toutes deux dans l'accent, toutes deux discrètes :
   elles ponctuent une carte, elles ne la remplacent pas. */
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

/* La jauge : la part qu'un chiffre représente, dessinée sous lui. */
.sf-gauge { display: block; height: 1.4cqw; border-radius: 9999px; background: color-mix(in srgb, currentColor 14%, transparent); overflow: hidden; }
.sf-gauge > i { display: block; height: 100%; background: var(--slide-accent); }

/* Les marques, ramenées à une même hauteur optique plutôt qu'à une même
   largeur : c'est la hauteur qu'un oeil compare, et `contain` garde chacune
   entière dans sa case. */
.sf-logos { flex: 1; min-height: 0; display: grid; grid-template-columns: repeat(var(--logos, 4), 1fr); gap: 4cqw; align-items: center; justify-items: center; }
.sf-logo { display: block; width: 100%; height: 8cqw; }
.sf-logo img { width: 100%; height: 100%; object-fit: contain; }

/* La mosaïque. Les arrangements sont déclarés comme les gabarits le sont : à
   deux images une colonne chacune, à trois une grande et deux petites, au-delà
   une grille régulière. Le point de visée de chaque document est respecté,
   sans quoi un recadrage couperait les visages. */
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

/* Le sommaire. Le rang en chasse fixe et en accent, la ligne courante seule à
   pleine encre : c'est le contraste qui dit où on en est, pas une puce. */
.sf-agenda { flex: 1; min-height: 0; margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; justify-content: center; gap: 1.8cqw; }
.sf-agenda li { display: flex; align-items: baseline; gap: 3cqw; font-size: calc(4.2cqw * var(--fit)); opacity: 0.45; }
.sf-agenda li.is-current { opacity: 1; font-weight: 600; }
.sf-agenda-rank { font-family: var(--slide-body); font-size: calc(2.8cqw * var(--fit)); font-variant-numeric: tabular-nums; color: var(--slide-accent); }

/* Le témoignage. Le visage rond et petit : une citation reste une citation, la
   photo l'accompagne au lieu de la disputer. */
.sf-portrait { flex: 1; min-height: 0; display: flex; align-items: center; gap: 5cqw; }
.sf-portrait-face { flex: 0 0 auto; position: relative; width: 22cqw; aspect-ratio: 1; border-radius: 9999px; overflow: hidden; background: color-mix(in srgb, currentColor 10%, transparent); }
.sf-portrait-words { min-width: 0; display: flex; flex-direction: column; gap: 2cqw; }
.sf-portrait-quote { margin: 0; font-family: var(--slide-heading); font-size: calc(4.6cqw * var(--fit)); line-height: 1.3; font-style: italic; }
.sf-portrait-who { margin: 0; display: flex; flex-direction: column; font-size: calc(2.8cqw * var(--fit)); }
.sf-portrait-role { opacity: 0.7; }

/* L'aplat. Sous le contenu et sous le décor du deck, mais au-dessus du fond :
   c'est une forme posée sur la slide, pas une teinte du sol. */
.sf-band { position: absolute; background: var(--slide-accent); pointer-events: none; }

.slide-frame[data-band="left"] .sf-band { inset: 0 auto 0 0; width: 32%; }
.slide-frame[data-band="bottom"] .sf-band { inset: auto 0 0 0; height: 22%; }
.slide-frame[data-band="edge"] .sf-band { inset: 0 auto 0 0; width: 2.5cqw; }

/* Le contenu se pousse pour ne pas passer dessous. Le fin bord n'a pas besoin
   de plus que la marge que le cadre garde déjà. */
.slide-frame[data-band="left"] .slide-stage { padding-left: calc(32% - var(--frame-pad) + 4cqw); }
.slide-frame[data-band="bottom"] .slide-stage { padding-bottom: calc(22% - var(--frame-pad) + 3cqw); }

/* Le débord : l'image sort de la marge du cadre du côté où elle est posée, et
   va toucher le bord. La marge est lue plutôt que recopiée, sans quoi un deck
   à marges larges laisserait une bande de fond entre l'image et le bord. */
.slide-frame[data-bleed="on"] .sf-beside-media {
    margin-left: calc(var(--frame-pad) * -1);
    border-radius: 0;
}

.slide-frame[data-bleed="on"] .sf-beside.is-right .sf-beside-media {
    margin-left: 0;
    margin-right: calc(var(--frame-pad) * -1);
}

/* Les filets du deck : entre les deux colonnes, et sous le sur-titre. */
.slide-frame[data-rules="on"] .sf-columns > :last-child {
    border-left: 0.2cqw solid color-mix(in srgb, currentColor 22%, transparent);
    padding-left: 4cqw;
}

.slide-frame[data-rules="on"] .sf-kicker {
    padding-bottom: 1.4cqw;
    border-bottom: 0.2cqw solid color-mix(in srgb, var(--slide-accent) 45%, transparent);
}

/* Deux colonnes qui se répondent. Le filet entre elles dit l'opposition que
   le gabarit `split` laissait deviner. */
.sf-compare { flex: 1; min-height: 0; display: grid; grid-template-columns: 1fr 1fr; gap: 5cqw; align-items: start; }
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

/* Les chiffres, côte à côte. La valeur porte l'accent et la légende reste en
   encre : l'inverse ferait lire la légende avant le chiffre. */
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

/* La dernière slide. Les lignes de contact sous le mot, serrées, sans puce. */
.sf-end-lines { display: flex; flex-direction: column; gap: 0.8cqw; font-size: calc(3.2cqw * var(--fit)); opacity: 0.8; }

/* La casse des titres, décidée pour tout le deck. Les trois sélecteurs et pas
   un seul : ce sont trois classes différentes selon le gabarit, et un titre
   en capitales sur la couverture seulement ne serait pas une décision de deck. */
.slide-frame[data-title-case="upper"] :is(.sf-title, .sf-heading, .sf-section) {
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

/* La forme de la puce. La couleur, elle, est déjà celle de l'accent. */
.slide-frame[data-bullets="dash"] .sf-list { list-style-type: "–  "; }
.slide-frame[data-bullets="arrow"] .sf-list { list-style-type: "→  "; }
.slide-frame[data-bullets="check"] .sf-list { list-style-type: "✓  "; }
.slide-frame[data-bullets="number"] .sf-list { list-style: decimal outside; }

/* Le chiffre de section, derrière le titre et hors du calcul de place : posé
   dans le flux, il pousserait le titre et ferait rétrécir le texte par
   `useSlideFit` pour laisser de la place à une décoration. */
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

/* Le mot en accent. Pas de fond, contrairement à ce que `mark` fait par
   défaut dans un navigateur : sur une slide, un surlignage jaune serait la
   seule couleur du deck que personne n'a choisie. */
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
 * L'image occupe sa boîte, et la boîte décide.
 *
 * En grille avec `place-items: center`, le `height: 100%` de l'image se
 * résolvait contre une rangée dont la hauteur était décidée par l'image :
 * le navigateur rompait le cycle en revenant à la taille naturelle, une image
 * carrée de 1280 px se dessinait en 815 px de haut dans une boîte de 293, et
 * `overflow: hidden` la rognait en haut et en bas. Une image en `contain` qui
 * se fait rogner est précisément ce que `contain` promet de ne pas faire.
 *
 * En absolu contre une boîte positionnée, il n'y a plus de cycle : la boîte a
 * sa hauteur avant que l'image ne demande la sienne.
 */
.sf-image { position: relative; flex: 1; min-height: 0; overflow: hidden; border-radius: 0.25rem; background: color-mix(in srgb, currentColor 10%, transparent); }
.sf-image-mark { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); }
.sf-image-mark { width: 12cqw; height: 12cqw; border-radius: 9999px; background: currentColor; opacity: 0.25; }
/* `contain` par défaut : une capture rognée pour remplir le cadre perd
   justement le coin qu'on voulait montrer. Une photo, elle, gagne souvent à
   remplir, d'où le réglage par slide - et le point de visée qui va avec. */
.sf-image-file {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: var(--media-fit, contain);
    object-position: var(--media-focus, 50% 50%);
}

/* Le tenant-lieu d'un graphique dans une vignette : des hauteurs fixes, parce
   que les mesurer demanderait de lire les données pour trois pixels de haut. */
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

/* Trois colonnes et non un `space-between` : le texte du pied reste au centre
   de la slide même quand il n'y a ni logo ni numéro de part et d'autre. */
.sf-footer {
    /* Positionné, comme la scène : une couche de fond l'est aussi, et un
       élément non positionné passe dessous quoi qu'en dise l'ordre du DOM. */
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
