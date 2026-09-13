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
import { cells, headed } from "../cells.js";
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
});

const skin = computed(() => {
    const look = props.appearance;

    if (!look) return {};

    return {
        "--slide-bg": look.background,
        "--slide-ink": look.ink,
        "--slide-accent": look.accent,
        "--slide-heading": look.headingFont,
        "--slide-body": look.bodyFont,
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
    const url = props.slide.content.bgMediaUrl;

    if (!url) return null;

    return { url, dim: Math.min(Math.max(props.slide.content.bgDim ?? 40, 0), 90) / 100 };
});

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
            :style="[skin, media]"
        >
            <div v-if="background" class="sf-backdrop" aria-hidden="true">
                <img class="sf-backdrop-file" :src="background.url" alt="">
                <span
                    class="sf-backdrop-veil"
                    :style="{ opacity: background.dim }"
                />
            </div>

            <div ref="stage" class="slide-stage" :style="{ '--fit': fit }">
                <p v-if="kicker" class="sf-kicker">{{ kicker }}</p>

                <template v-if="slide.layout === 'title'">
                    <p class="sf-title" v-html="emphasis(slide.content.title)" />
                    <p v-if="!compact && slide.content.subtitle" class="sf-subtitle" v-html="emphasis(slide.content.subtitle)" />
                </template>

                <template v-else-if="slide.layout === 'section'">
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

                <template v-else-if="slide.layout === 'cards'">
                    <p v-if="slide.content.title" class="sf-heading sf-heading-small" v-html="emphasis(slide.content.title)" />
                    <div class="sf-cards" :style="{ '--cards': Math.min((slide.content.items ?? []).length || 1, 4) }">
                        <div v-for="(item, at) in slide.content.items ?? []" :key="at" class="sf-card">
                            <span class="sf-card-head" v-html="emphasis(headed(item).head)" />
                            <span v-if="!compact && headed(item).body" class="sf-card-body" v-html="emphasis(headed(item).body)" />
                        </div>
                    </div>
                </template>

                <template v-else-if="slide.layout === 'timeline'">
                    <p v-if="slide.content.title" class="sf-heading sf-heading-small" v-html="emphasis(slide.content.title)" />
                    <ol class="sf-steps">
                        <li v-for="(step, at) in slide.content.steps ?? []" :key="at" class="sf-step">
                            <span class="sf-step-mark" />
                            <span class="sf-step-head" v-html="emphasis(headed(step).head)" />
                            <span v-if="!compact && headed(step).body" class="sf-step-body" v-html="emphasis(headed(step).body)" />
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
                    </div>
                    <p v-if="!compact && slide.content.caption" class="sf-caption" v-html="emphasis(slide.content.caption)" />
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

    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    padding: 6cqw;
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

.sf-kicker {
    margin: 0;
    font-family: var(--slide-heading);
    font-size: calc(2.8cqw * var(--fit));
    font-weight: 600;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: var(--slide-accent);
}

.sf-title { margin: 0; font-family: var(--slide-heading); font-size: calc(8cqw * var(--fit)); font-weight: 600; line-height: 1.1; }
.sf-subtitle { margin: 0; font-size: calc(4cqw * var(--fit)); opacity: 0.7; }
.sf-section { margin: 0; font-family: var(--slide-heading); font-size: calc(7cqw * var(--fit)); font-weight: 600; text-align: center; }
.sf-heading { margin: 0; font-family: var(--slide-heading); font-size: calc(6cqw * var(--fit)); font-weight: 600; }
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
