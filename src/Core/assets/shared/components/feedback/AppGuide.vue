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
 * The content is free: steps (`<ol>`), a sentence, a link. The text style is
 * set here, so that every panel reads the same.
 */
import { computed } from "vue";
import { BookOpen, ChevronDown } from "lucide-vue-next";
import { useGuidePreference } from "@/shared/composables/useGuidePreference.js";

const props = defineProps({
    title: { type: String, required: true },
    /** Open at first, as long as the reader has chosen nothing. */
    open: { type: Boolean, default: true },
    /** Accepted and ignored: the choice to open or collapse is shared by all panels. */
    storageKey: { type: String, default: "" },
    /**
     * Rounded corners, by default. `false` removes them, for a panel embedded in
     * another block (against an edge, under a header) rather than placed next to
     * it: a rounded corner against a straight edge looks like a defect. Placed
     * on its own, the panel stays rounded and takes its margins from the caller.
     */
    rounded: { type: Boolean, default: true },
});

const { choice, remember } = useGuidePreference();

const isOpen = computed(() => choice.value ?? props.open);

/**
 * `toggle` also fires when the state changes through code (another panel has
 * just been collapsed): only a gap with the expected state comes from the
 * reader.
 */
function onToggle(event) {
    if (event.target.open === isOpen.value) return;

    remember(event.target.open);
}
</script>

<template>
    <!-- A named region rather than an <aside> or a <section>: the screens
         keep those tags for their side panels and their groups, and their
         tests count them. `data-guide` points to it without ambiguity. -->
    <div
        data-guide
        role="region"
        class="min-w-0 border border-dashed border-line p-3 sm:p-4"
        :class="rounded ? 'rounded-lg' : 'rounded-none'"
        :aria-label="title"
    >
        <details :open="isOpen" class="group" v-on:toggle="onToggle">
            <summary class="flex cursor-pointer list-none items-center gap-2 text-sm font-medium text-primary [&::-webkit-details-marker]:hidden">
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
