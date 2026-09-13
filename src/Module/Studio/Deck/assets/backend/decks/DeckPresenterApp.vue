<script setup>
/**
 * What the presenter sees, on the screen the room does not.
 *
 * The speaker notes have been stored in a column of their own since the module
 * was written, precisely so no layout could put them on the wall by accident.
 * This is the page that finally reads them, and it is a second window rather
 * than a panel in the player for the same reason: one screen is the audience's
 * screen, and anything drawn there is public.
 *
 * The two windows agree through a `BroadcastChannel`, which is same-origin and
 * in-process. The notes never leave the machine they are read on.
 *
 * Either window drives. A presenter with a clicker steps the wall; a presenter
 * looking down here steps it from here.
 */
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { ChevronLeft, ChevronRight, Pause, Play, RotateCcw } from "lucide-vue-next";
import SlideFrame from "./components/SlideFrame.vue";
import { useDeckStage } from "./composables/useDeckStage.js";

const props = defineProps({
    deck: { type: Object, required: true },
});

const { t } = useI18n();

const slides = props.deck.slides ?? [];
const { at, linked, announce } = useDeckStage(props.deck.id);

const current = computed(() => slides[at.value] ?? null);
const next = computed(() => slides[at.value + 1] ?? null);

function step(by) {
    const to = at.value + by;

    if (to >= 0 && to < slides.length) announce(to);
}

function onKey(event) {
    const keys = { ArrowRight: 1, ArrowDown: 1, PageDown: 1, " ": 1, ArrowLeft: -1, ArrowUp: -1, PageUp: -1 };

    if (!(event.key in keys)) return;

    event.preventDefault();
    step(keys[event.key]);
}

/**
 * The clock, counting up from the first press rather than from the page load.
 *
 * A presenter opens this window while the room fills, and a timer that had been
 * running for eleven minutes by the time they say hello is a timer nobody
 * looks at twice.
 */
const elapsed = ref(0);
const running = ref(false);
let ticker = null;

function tick() {
    if (running.value) elapsed.value += 1;
}

function toggle() {
    running.value = !running.value;
}

function reset() {
    elapsed.value = 0;
    running.value = false;
}

const clock = computed(() => {
    const minutes = Math.floor(elapsed.value / 60);
    const seconds = elapsed.value % 60;

    return `${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}`;
});

onMounted(() => {
    window.addEventListener("keydown", onKey);
    ticker = window.setInterval(tick, 1000);

    // Says "here I am" so a player already running lands this window on the
    // slide that is on the wall rather than on the first one.
    announce(at.value);
});

onBeforeUnmount(() => {
    window.removeEventListener("keydown", onKey);
    window.clearInterval(ticker);
});
</script>

<template>
    <div class="presenter">
        <div class="presenter-stage">
            <div class="presenter-now">
                <p class="presenter-label">
                    {{ t("backend.studio.decks.presenter_now") }}
                    <span class="presenter-count">{{ at + 1 }} / {{ slides.length }}</span>
                </p>
                <SlideFrame
                    v-if="current"
                    :slide="current"
                    :appearance="deck.appearance"
                    :index="at + 1"
                />
            </div>

            <div class="presenter-next">
                <p class="presenter-label">{{ t("backend.studio.decks.presenter_next") }}</p>
                <SlideFrame
                    v-if="next"
                    :slide="next"
                    :appearance="deck.appearance"
                    :index="at + 2"
                />
                <p v-else class="presenter-end">{{ t("backend.studio.decks.presenter_end") }}</p>
            </div>
        </div>

        <div class="presenter-notes">
            <div class="presenter-bar">
                <button
                    type="button"
                    class="presenter-button"
                    :disabled="at === 0"
                    :aria-label="t('backend.studio.decks.previous_slide')"
                    v-on:click="step(-1)"
                >
                    <ChevronLeft class="h-5 w-5" :stroke-width="2" />
                </button>
                <button
                    type="button"
                    class="presenter-button"
                    :disabled="at >= slides.length - 1"
                    :aria-label="t('backend.studio.decks.next_slide')"
                    v-on:click="step(1)"
                >
                    <ChevronRight class="h-5 w-5" :stroke-width="2" />
                </button>

                <span class="presenter-clock">{{ clock }}</span>

                <button
                    type="button"
                    class="presenter-button"
                    :aria-label="running ? t('backend.studio.decks.timer_pause') : t('backend.studio.decks.timer_start')"
                    v-on:click="toggle"
                >
                    <Pause v-if="running" class="h-4 w-4" :stroke-width="2" />
                    <Play v-else class="h-4 w-4" :stroke-width="2" />
                </button>
                <button
                    type="button"
                    class="presenter-button"
                    :aria-label="t('backend.studio.decks.timer_reset')"
                    v-on:click="reset"
                >
                    <RotateCcw class="h-4 w-4" :stroke-width="2" />
                </button>

                <span class="presenter-link" :class="linked ? 'is-live' : ''">
                    {{ linked ? t("backend.studio.decks.presenter_linked") : t("backend.studio.decks.presenter_alone") }}
                </span>
            </div>

            <p v-if="current?.speakerNotes" class="presenter-note">{{ current.speakerNotes }}</p>
            <p v-else class="presenter-note is-empty">{{ t("backend.studio.decks.presenter_no_note") }}</p>
        </div>
    </div>
</template>

<style scoped>
.presenter {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    min-height: 100dvh;
    padding: 1.25rem;
    background: #0b0d10;
    color: #e6e9ef;
}

/* La slide en cours large, la suivante petite : ce qu'on regarde en parlant
   est le texte qu'on est en train de commenter, pas celui d'après. */
.presenter-stage { display: grid; gap: 1.25rem; grid-template-columns: 1fr; }

@media (min-width: 64rem) {
    .presenter-stage { grid-template-columns: 2fr 1fr; align-items: start; }
}

.presenter-now, .presenter-next { display: flex; flex-direction: column; gap: 0.5rem; min-width: 0; }

.presenter-label {
    margin: 0;
    display: flex;
    justify-content: space-between;
    gap: 0.75rem;
    font-size: 0.7rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: #8b94a3;
}

.presenter-count { font-variant-numeric: tabular-nums; letter-spacing: 0; }
.presenter-end { margin: 0; padding: 2rem 0; text-align: center; font-size: 0.85rem; color: #8b94a3; }

.presenter-notes {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    padding: 1rem;
    border-radius: 0.75rem;
    background: #141920;
}

.presenter-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; }

.presenter-button {
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

.presenter-button:hover:not(:disabled) { background: rgb(255 255 255 / 0.1); }
.presenter-button:disabled { opacity: 0.3; cursor: default; }
.presenter-button:focus-visible { outline: 2px solid #6ee7b7; outline-offset: 2px; }

.presenter-clock { margin-left: 0.5rem; font-size: 1.5rem; font-variant-numeric: tabular-nums; }

.presenter-link { margin-left: auto; font-size: 0.72rem; color: #8b94a3; }
.presenter-link.is-live { color: #6ee7b7; }

/* Grand, parce qu'il est lu de biais, à un mètre, en parlant. */
.presenter-note { margin: 0; font-size: 1.35rem; line-height: 1.5; white-space: pre-wrap; }
.presenter-note.is-empty { font-size: 0.9rem; color: #8b94a3; }
</style>
