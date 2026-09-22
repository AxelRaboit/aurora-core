<script setup>
/**
 * The deck, full screen, one slide at a time.
 *
 * **The speaker notes are not here, and that is the whole point of them being
 * a column rather than a slot.** This view is what the room sees: a single
 * screen is the audience's screen, so anything drawn here is public. A
 * presenter view on a second display is a different feature, with a different
 * risk, and it would have to say so.
 *
 * The slide is letterboxed rather than stretched. A 16:9 frame on a 16:10
 * projector has bars above and below, and that is the honest answer: filling
 * the screen would crop a corner of something somebody wrote.
 *
 * Keyboard first, because that is what a presentation is driven with - a
 * clicker sends Page Up and Page Down, which is why they are bound alongside
 * the arrows.
 *
 * The presenter window, when one is open, steps in lockstep with this one
 * through a `BroadcastChannel`: either window drives, so a presenter reading
 * their notes can step from there and a clicker still steps from here.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { ChevronLeft, ChevronRight, Grid2x2, Radio, X } from "lucide-vue-next";
import SlideFrame from "./SlideFrame.vue";
import { useDeckStage } from "../composables/useDeckStage.js";

const props = defineProps({
    slides: { type: Array, default: () => [] },
    /** The deck's resolved look, handed down to the frame unchanged. */
    appearance: { type: Object, default: null },
    /** Where to open on, so "present from here" lands on the right slide. */
    startAt: { type: Number, default: 0 },
    /** The deck's id, which is the room the presenter window listens in. */
    channel: { type: [String, Number], default: null },
});

const emit = defineEmits(["close"]);

const at = ref(Math.min(Math.max(props.startAt, 0), Math.max(props.slides.length - 1, 0)));
const stage = ref(null);

const { t } = useI18n();

const current = computed(() => props.slides[at.value] ?? null);
const isFirst = computed(() => at.value === 0);
const isLast = computed(() => at.value >= props.slides.length - 1);

/**
 * Named `link` and not `stage`: the template ref above is the element that goes
 * full screen, and two bindings by that name would have the overlay opening in
 * a channel object.
 */
const link = useDeckStage(props.channel);

link.onMove((index) => {
    if (index >= 0 && index < props.slides.length) at.value = index;
});

/**
 * The grid of every slide, for the question asked at the end.
 *
 * Somebody asks about the third chapter and the answer is nineteen presses of
 * an arrow, in front of the room. The grid is one press, a click, and back.
 */
const overview = ref(false);

/**
 * Which way the last step went, which is all a sliding transition needs.
 *
 * Named on the stage rather than passed to the frame: the frame draws a slide
 * and knows nothing about what came before it, and teaching it would be
 * teaching every one of its five callers.
 */
const direction = ref(1);

/**
 * How many lines the slide on screen can bring in one at a time.
 *
 * **Counted from the content, never measured from the page.** The lines are in
 * a list slot, so their number is known before anything is drawn; asking the
 * DOM would tie the way a deck is driven to the way it happens to be laid out
 * that day, and would answer differently on a thumbnail.
 *
 * Zero for every slide that did not ask for it, which is every slide written
 * before today, so nothing about the arrows changes for them.
 */
const REVEAL_SLOTS = ["bullets", "items", "steps", "figures", "lines"];

const revealable = computed(() => {
    const content = props.slides[at.value]?.content;

    if (content?.reveal !== true) return 0;

    const slot = REVEAL_SLOTS.find((name) => Array.isArray(content[name]));

    return slot ? content[slot].length : 0;
});

/** How many of them are showing. Reset whenever the slide itself changes. */
const shown = ref(0);

watch(at, () => {
    shown.value = 0;
});

/**
 * One press, one thing: the next line if there is one, else the next slide.
 *
 * Clamped rather than wrapping, because the end of a deck is the end of it.
 * Going back takes the last line away before it takes the slide away, so a
 * press backwards always undoes exactly the press forwards that preceded it.
 */
function step(by) {
    if (by > 0 && shown.value < revealable.value) {
        shown.value += 1;

        return;
    }

    if (by < 0 && shown.value > 0) {
        shown.value -= 1;

        return;
    }

    const next = at.value + by;

    if (next < 0 || next >= props.slides.length) return;

    direction.value = by > 0 ? 1 : -1;
    at.value = next;

    // Coming back into a slide that reveals, everything it had is already out:
    // walking backwards through a deck should not make the reader press
    // through every line again in reverse.
    shown.value = by < 0 ? revealableAt(next) : 0;
    link.announce(next);
}

function revealableAt(index) {
    const content = props.slides[index]?.content;

    if (content?.reveal !== true) return 0;

    const slot = REVEAL_SLOTS.find((name) => Array.isArray(content[name]));

    return slot ? content[slot].length : 0;
}

function jumpTo(index) {
    direction.value = index > at.value ? 1 : -1;
    at.value = index;
    link.announce(index);
    overview.value = false;
}

/**
 * The name of the Vue transition, or nothing at all for the cut.
 *
 * The slide being entered decides before the deck does. A hard cut into a
 * section slide and a fade everywhere else is a rhythm only that slide knows,
 * and a deck-wide setting cannot express it.
 */
const transition = computed(() => {
    const own = props.slides[at.value]?.content?.transition;
    const kind = ["none", "fade", "slide"].includes(own)
        ? own
        : (props.appearance?.transition ?? "fade");

    if (kind === "none") return "";

    return kind === "slide"
        ? `deck-slide-${direction.value > 0 ? "forward" : "back"}`
        : "deck-fade";
});

/**
 * A circle under the pointer, for showing a line on a table.
 *
 * A toggle rather than a held key: a held key repeats, and a laser that
 * flickers at the keyboard's repeat rate is worse than no laser. It follows the
 * real pointer rather than replacing the cursor, so nothing is lost if a
 * presenter forgets it is on.
 */
const pointer = ref(false);
const spot = ref({ x: -100, y: -100 });

function onPointerMove(event) {
    spot.value = { x: event.clientX, y: event.clientY };
    wake();
}

/**
 * The mouse cursor, hidden when it has been still for a while.
 *
 * An arrow parked in the middle of a projected slide is the single most common
 * blemish on a presentation, and nobody notices their own.
 */
const idle = ref(false);
let idleTimer = null;

function wake() {
    idle.value = false;
    window.clearTimeout(idleTimer);
    idleTimer = window.setTimeout(() => (idle.value = true), 2500);
}

function close() {
    if (document.fullscreenElement) document.exitFullscreen().catch(() => {});

    emit("close");
}

function onKey(event) {
    const keys = {
        ArrowRight: 1,
        ArrowDown: 1,
        PageDown: 1,
        " ": 1,
        ArrowLeft: -1,
        ArrowUp: -1,
        PageUp: -1,
    };

    if (event.key === "Escape") {
        // The grid first: Escape closes what is on top, and a reader who opened
        // it to look for a slide has not asked to leave the presentation.
        if (overview.value) {
            overview.value = false;

            return;
        }

        // Not preventDefault: leaving the browser's own full screen already
        // fires Escape, and swallowing it would leave the overlay up with the
        // chrome back.
        close();

        return;
    }

    const key = event.key.toLowerCase();

    if (key === "o") {
        event.preventDefault();
        overview.value = !overview.value;

        return;
    }

    if (key === "p") {
        event.preventDefault();
        pointer.value = !pointer.value;

        return;
    }

    if (event.key in keys) {
        event.preventDefault();

        // Stepping from the grid picks a slide and closes it, which is the
        // gesture somebody is making when they arrow around in there.
        overview.value = false;
        step(keys[event.key]);
    }
}

onMounted(() => {
    window.addEventListener("keydown", onKey);
    document.body.style.overflow = "hidden";
    wake();

    // Asked for, not required: a browser may refuse it outside a user gesture,
    // and the overlay is a complete presentation view either way.
    stage.value?.requestFullscreen?.().catch(() => {});

    stage.value?.focus();

    // Says which slide is up, so a presenter window opened mid-talk lands on it
    // rather than on the first.
    link.announce(at.value);
});

onBeforeUnmount(() => {
    window.removeEventListener("keydown", onKey);
    window.clearTimeout(idleTimer);
    document.body.style.overflow = "";
});
</script>

<template>
    <div
        ref="stage"
        class="deck-player"
        tabindex="-1"
        role="dialog"
        aria-modal="true"
        :aria-label="t('backend.studio.decks.present')"
        :class="idle && !overview ? 'is-idle' : ''"
        v-on:mousemove="onPointerMove"
    >
        <div class="deck-player-stage">
            <Transition :name="transition">
                <!-- La clé porte l'index : sans elle Vue réutilise le même
                     composant et la transition n'a rien à faire entrer ni
                     sortir. -->
                <SlideFrame
                    v-if="current"
                    :key="at"
                    :slide="current"
                    :appearance="appearance"
                    :revealed="revealable > 0 ? shown : null"
                    live
                    :index="at + 1"
                />
            </Transition>
        </div>

        <!-- Le sommaire, par-dessus la slide plutôt qu'à sa place : on y entre
             pour en ressortir, et remplacer l'écran ferait perdre où on en
             était à la salle aussi. -->
        <div v-if="overview" class="deck-player-grid" role="dialog" :aria-label="t('backend.studio.decks.overview')">
            <button
                v-for="(slide, index) in slides"
                :key="slide.id"
                type="button"
                class="deck-player-cell"
                :class="index === at ? 'is-current' : ''"
                :aria-current="index === at ? 'true' : undefined"
                v-on:click="jumpTo(index)"
            >
                <SlideFrame :slide="slide" :appearance="appearance" :index="index + 1" compact />
                <span class="deck-player-cell-number">{{ index + 1 }}</span>
            </button>
        </div>

        <span
            v-if="pointer"
            class="deck-player-spot"
            aria-hidden="true"
            :style="{ left: `${spot.x}px`, top: `${spot.y}px` }"
        />

        <div class="deck-player-bar">
            <button
                type="button"
                class="deck-player-button"
                :disabled="isFirst"
                :aria-label="t('backend.studio.decks.previous_slide')"
                v-on:click="step(-1)"
            >
                <ChevronLeft class="h-5 w-5" :stroke-width="2" />
            </button>

            <span class="deck-player-count">{{ at + 1 }} / {{ slides.length }}</span>

            <button
                type="button"
                class="deck-player-button"
                :class="overview ? 'is-on' : ''"
                :aria-pressed="overview"
                :aria-label="t('backend.studio.decks.overview')"
                v-on:click="overview = !overview"
            >
                <Grid2x2 class="h-5 w-5" :stroke-width="2" />
            </button>

            <button
                type="button"
                class="deck-player-button"
                :class="pointer ? 'is-on' : ''"
                :aria-pressed="pointer"
                :aria-label="t('backend.studio.decks.pointer')"
                v-on:click="pointer = !pointer"
            >
                <Radio class="h-5 w-5" :stroke-width="2" />
            </button>

            <button
                type="button"
                class="deck-player-button"
                :disabled="isLast"
                :aria-label="t('backend.studio.decks.next_slide')"
                v-on:click="step(1)"
            >
                <ChevronRight class="h-5 w-5" :stroke-width="2" />
            </button>

            <button
                type="button"
                class="deck-player-button"
                :aria-label="t('shared.common.close')"
                v-on:click="close"
            >
                <X class="h-5 w-5" :stroke-width="2" />
            </button>
        </div>
    </div>
</template>

<style scoped>
.deck-player {
    position: fixed;
    inset: 0;
    z-index: 100;
    display: flex;
    flex-direction: column;
    background: #0b0d10;
    outline: none;
}

/**
 * The slide, as large as it fits without changing shape.
 *
 * `min()` against both axes is what letterboxes it: the width is whichever of
 * the two constraints binds first, so a tall window bars the sides and a wide
 * one bars the top. Sizing on one axis only would crop the other.
 */
.deck-player-stage {
    position: relative;
    flex: 1;
    display: grid;
    place-items: center;
    padding: 2rem;
    min-height: 0;
}

.deck-player-stage > * {
    /* `100dvh` moins la barre et les marges, converti en largeur par le
       rapport : c'est la contrainte de hauteur exprimée en largeur, et `min`
       retient celle des deux qui mord la première. `dvh` plutôt que `vh`
       parce que la barre d'adresse d'un téléphone change la hauteur réelle. */
    width: min(100%, calc((100dvh - 8rem) * 16 / 9));
    max-width: 1600px;
}

.deck-player-bar {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 1rem;
    padding: 0.75rem;
    color: #9aa4b2;
    /* Discreet until wanted: a bar at full strength under every slide is the
       one thing in the room nobody came to look at. */
    opacity: 0.35;
    transition: opacity 0.15s ease;
}

.deck-player:hover .deck-player-bar,
.deck-player:focus-within .deck-player-bar {
    opacity: 1;
}

.deck-player-button {
    display: grid;
    place-items: center;
    width: 2.25rem;
    height: 2.25rem;
    border: 0;
    border-radius: 9999px;
    background: transparent;
    color: inherit;
    cursor: pointer;
}

.deck-player-button:hover:not(:disabled) { background: rgba(255, 255, 255, 0.1); }
.deck-player-button:disabled { opacity: 0.3; cursor: default; }
.deck-player-button:focus-visible { outline: 2px solid #6ee7b7; outline-offset: 2px; }

.deck-player-count { font-variant-numeric: tabular-nums; font-size: 0.85rem; }

.deck-player-button.is-on { background: rgb(110 231 183 / 0.18); color: #6ee7b7; }

/* Le curseur disparaît quand la souris ne bouge plus : une flèche garée au
   milieu d'une slide projetée est la tache que personne ne voit sur sa propre
   présentation. Elle revient au premier mouvement. */
.deck-player.is-idle { cursor: none; }

/**
 * Les deux couches, empilées pendant la transition.
 *
 * La scène est une grille d'une seule case : les deux slides y tombent l'une
 * sur l'autre au lieu de se pousser, ce qui est la seule façon d'avoir un
 * fondu plutôt qu'un défilement vertical d'une demi-seconde.
 */
.deck-player-stage > * { grid-area: 1 / 1; }

.deck-fade-enter-active,
.deck-fade-leave-active { transition: opacity 0.18s ease; }
.deck-fade-enter-from,
.deck-fade-leave-to { opacity: 0; }

.deck-slide-forward-enter-active,
.deck-slide-forward-leave-active,
.deck-slide-back-enter-active,
.deck-slide-back-leave-active { transition: transform 0.22s ease, opacity 0.22s ease; }

.deck-slide-forward-enter-from { transform: translateX(4%); opacity: 0; }
.deck-slide-forward-leave-to { transform: translateX(-4%); opacity: 0; }
.deck-slide-back-enter-from { transform: translateX(-4%); opacity: 0; }
.deck-slide-back-leave-to { transform: translateX(4%); opacity: 0; }

/* Le sommaire, par-dessus. */
.deck-player-grid {
    position: absolute;
    inset: 0;
    z-index: 10;
    overflow-y: auto;
    display: grid;
    /* Assez large pour qu'un titre se lise : on vient ici reconnaître une
       slide, pas compter combien il y en a. */
    grid-template-columns: repeat(auto-fill, minmax(15rem, 1fr));
    align-content: start;
    gap: 0.75rem;
    padding: 1.5rem;
    /* Opaque, et non un voile : à 96 % la slide en cours transparaissait en
       grand derrière les vignettes, et la grille avait l'air posée sur une
       image fantôme plutôt que d'être l'écran qu'on regarde. */
    background: #0b0d10;
}

.deck-player-cell {
    position: relative;
    display: block;
    padding: 0;
    border: 2px solid transparent;
    border-radius: 0.4rem;
    background: transparent;
    cursor: pointer;
}

.deck-player-cell.is-current { border-color: #6ee7b7; }
.deck-player-cell:focus-visible { outline: 2px solid #6ee7b7; outline-offset: 2px; }

.deck-player-cell-number {
    position: absolute;
    right: 0.35rem;
    bottom: 0.35rem;
    font-size: 0.7rem;
    font-variant-numeric: tabular-nums;
    color: #9aa4b2;
}

/**
 * Le point lumineux, hors de tout flux et insensible au clic.
 *
 * `pointer-events: none` est ce qui fait qu'il suit la souris sans jamais se
 * mettre entre elle et ce qu'elle vise : sans ça, le cercle recevrait
 * lui-même le `mousemove` et se figerait sous le curseur.
 */
.deck-player-spot {
    position: fixed;
    z-index: 20;
    /* Assez grand et assez dense pour survivre à un vidéoprojecteur : à
       l'écran on le voit de toute façon, au mur c'est le seul endroit où ça
       se joue. */
    width: 3.5rem;
    height: 3.5rem;
    margin: -1.75rem 0 0 -1.75rem;
    border-radius: 9999px;
    pointer-events: none;
    background: radial-gradient(circle, rgb(248 113 113 / 0.85) 0%, rgb(248 113 113 / 0.45) 40%, rgb(248 113 113 / 0.12) 62%, transparent 72%);
}

@media (prefers-reduced-motion: reduce) {
    .deck-player-bar { transition: none; }

    /* Ce que ce réglage système demande, c'est justement de ne pas voir les
       slides bouger. Le thème du deck ne prime pas là-dessus. */
    .deck-fade-enter-active,
    .deck-fade-leave-active,
    .deck-slide-forward-enter-active,
    .deck-slide-forward-leave-active,
    .deck-slide-back-enter-active,
    .deck-slide-back-leave-active { transition: none; }
}
</style>
