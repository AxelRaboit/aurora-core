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
 */
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { ChevronLeft, ChevronRight, X } from "lucide-vue-next";
import SlideFrame from "./SlideFrame.vue";

const props = defineProps({
    slides: { type: Array, default: () => [] },
    /** The deck's resolved look, handed down to the frame unchanged. */
    appearance: { type: Object, default: null },
    /** Where to open on, so "present from here" lands on the right slide. */
    startAt: { type: Number, default: 0 },
});

const emit = defineEmits(["close"]);

const at = ref(Math.min(Math.max(props.startAt, 0), Math.max(props.slides.length - 1, 0)));
const stage = ref(null);

const { t } = useI18n();

const current = computed(() => props.slides[at.value] ?? null);
const isFirst = computed(() => at.value === 0);
const isLast = computed(() => at.value >= props.slides.length - 1);

/** Clamped rather than wrapping: the end of a deck is the end of it. */
function step(by) {
    const next = at.value + by;

    if (next >= 0 && next < props.slides.length) at.value = next;
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
        // Not preventDefault: leaving the browser's own full screen already
        // fires Escape, and swallowing it would leave the overlay up with the
        // chrome back.
        close();

        return;
    }

    if (event.key in keys) {
        event.preventDefault();
        step(keys[event.key]);
    }
}

onMounted(() => {
    window.addEventListener("keydown", onKey);
    document.body.style.overflow = "hidden";

    // Asked for, not required: a browser may refuse it outside a user gesture,
    // and the overlay is a complete presentation view either way.
    stage.value?.requestFullscreen?.().catch(() => {});

    stage.value?.focus();
});

onBeforeUnmount(() => {
    window.removeEventListener("keydown", onKey);
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
    >
        <div class="deck-player-stage">
            <SlideFrame
                v-if="current"
                :slide="current"
                :appearance="appearance"
                :index="at + 1"
            />
        </div>

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

@media (prefers-reduced-motion: reduce) {
    .deck-player-bar { transition: none; }
}
</style>
