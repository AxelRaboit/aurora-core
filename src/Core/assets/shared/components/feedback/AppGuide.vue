<script setup>
/**
 * A "How it works" panel, next to what it explains.
 *
 * **Next to it, not in a separate help.** Documentation that has to be
 * fetched elsewhere is not read at the moment of hesitation; a panel placed
 * near the action is. It says what the screen does, in the order it is used,
 * and confirms what is about to happen before the click.
 *
 * **One choice for all panels** (`useGuidePreference`): collapsing this one
 * collapses them all, and the choice is remembered. As long as the reader
 * has chosen nothing, the panel follows `open`; an integration guide thus
 * stays open as long as nothing is connected.
 *
 * **Open once, then folded.** Without an `open` from the caller and without
 * a choice from the reader, a panel opens the first time its screen is
 * visited (`storageKey`, or the title) and stays folded on the next visits.
 * Folded, it is one discreet line rather than a dashed box: open on every
 * screen, the panels pushed every list below the fold (UI audit of
 * 07/10/2026).
 *
 * The content is free: steps (`<ol>`), a sentence, a link. The text style is
 * set here, so that every panel reads the same.
 */
import { computed, onMounted, ref } from "vue";
import { BookOpen, ChevronDown } from "lucide-vue-next";
import { useGuidePreference } from "@/shared/composables/useGuidePreference.js";

const props = defineProps({
    title: { type: String, required: true },
    /**
     * The caller's say, as long as the reader has chosen nothing. Left out,
     * the panel opens on the first visit of its screen only.
     */
    open: { type: Boolean, default: null },
    /** Names the screen, for « already shown once ». The title stands in. */
    storageKey: { type: String, default: "" },
    /**
     * Rounded corners, by default. `false` removes them, for a panel embedded in
     * another block (against an edge, under a header) rather than placed next to
     * it: a rounded corner against a straight edge looks like a defect. Placed
     * on its own, the panel stays rounded and takes its margins from the caller.
     */
    rounded: { type: Boolean, default: true },
});

const { choice, remember, hasSeen, markSeen } = useGuidePreference();

const screenKey = props.storageKey || props.title;
// Read once, before this visit counts: the panel must not fold under the
// reader's eyes the moment it has been marked as seen.
const firstVisit = !hasSeen(screenKey);

const isOpen = computed(() => choice.value ?? props.open ?? firstVisit);

/** Whether the panel is unfolded right now, for its frame. */
const expanded = ref(isOpen.value);

onMounted(() => markSeen(screenKey));

/**
 * `toggle` also fires when the state changes through code (another panel has
 * just been collapsed): only a gap with the expected state comes from the
 * reader.
 */
function onToggle(event) {
    expanded.value = event.target.open;
    if (event.target.open === isOpen.value) return;

    remember(event.target.open);
}
</script>

<template>
    <!-- A named region rather than an <aside> or a <section>: the screens
         keep those tags for their side panels and their groups, and their
         tests count them. `data-guide` points to it without ambiguity. -->
    <!-- Folded, the frame goes: one muted line the reader can open, not a
         box the width of the page on every screen.

         Open, it is a card like its neighbours - surface, line, rounding -
         rather than the dashed outline it used to wear. A dashed frame is
         the house sign for a drop zone, a place waiting for something; on a
         panel of text it read as an unfinished block (visual redesign of
         the suite, 09/10/2026). -->
    <div
        data-guide
        role="region"
        class="min-w-0"
        :class="[
            expanded ? 'border border-line bg-surface p-4 sm:p-5' : '',
            rounded ? 'rounded-xl' : 'rounded-none',
        ]"
        :aria-label="title"
    >
        <details :open="isOpen" class="group" v-on:toggle="onToggle">
            <summary
                class="flex cursor-pointer list-none items-center gap-2 text-sm [&::-webkit-details-marker]:hidden"
                :class="expanded ? 'font-medium text-primary' : 'w-fit text-muted hover:text-primary'"
            >
                <BookOpen class="h-4 w-4 shrink-0 text-muted" :stroke-width="2" />
                <span class="min-w-0 flex-1">{{ title }}</span>
                <ChevronDown class="h-4 w-4 shrink-0 text-muted transition-transform group-open:rotate-180" :stroke-width="2" />
            </summary>
            <div class="mt-3 flex flex-col gap-3 text-sm text-secondary">
                <slot />
            </div>
        </details>
    </div>
</template>
