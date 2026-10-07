<script setup>
import { computed } from "vue";

const props = defineProps({
    color: { type: String, default: "default" },
    // A single size: thirty pixels for a finger, the old tight fit for a mouse.
    // A `compact` variant existed and was never called - zero times out of
    // ninety-seven buttons - so it promised a choice nobody made and that would
    // have had to be maintained.
    size: { type: String, default: "md" },
    title: { type: String, default: null },
    ariaLabel: { type: String, default: null },
    href: { type: String, default: null },
    /**
     * A switch that is on (panel open, view selected): the icon takes the
     * accent colour on a tinted background. Replaces the `variant="primary"`
     * that some calls passed and that this component never read.
     */
    active: { type: Boolean, default: false },
    /**
     * The icon, when the call does not pass it in the slot. Fifteen buttons of
     * the grid editor (move up, move down, remove a tab, an image, a slide)
     * passed `:icon` to a component that did not read it: they showed up empty
     * (seen on 02/10/2026).
     */
    icon: { type: [Object, Function], default: null },
});

const colors = {
    default: { text: "text-secondary hover:text-primary",    bg: "hover:bg-surface-2" },
    sky:     { text: "text-secondary hover:text-sky-400",     bg: "hover:bg-surface-2" },
    accent:  { text: "text-secondary hover:text-accent-400",  bg: "hover:bg-surface-2" },
    rose:    { text: "text-secondary hover:text-rose-400",    bg: "hover:bg-rose-500/10" },
    // The name that five calls of the grid editor used: it fell back to
    // `default` and their trash buttons never turned red.
    danger:  { text: "text-secondary hover:text-rose-400",    bg: "hover:bg-rose-500/10" },
    emerald: { text: "text-secondary hover:text-emerald-400", bg: "hover:bg-emerald-500/10" },
    amber:   { text: "text-secondary hover:text-amber-400",   bg: "hover:bg-surface-2" },
    // For use on bright, non-Aurora surfaces (post-it sticky notes, light
    // overlays, custom-colored cards). Aurora tokens (text-secondary etc.)
    // assume a dark surface and become invisible on bright backgrounds -
    // this variant ships a dark-on-light palette tuned for that case.
    "on-light": { text: "text-black/50 hover:text-black/80",  bg: "hover:bg-black/10" },
};

/**
 * **Thirty pixels under the thumb, the previous size with a mouse.**
 *
 * Six pixels of padding around a fourteen-pixel icon make a target of
 * twenty-six, which suits a cursor very well and a finger badly: it is the
 * measurement that moved the tabs of a space to thirty this morning, and
 * these ninety-seven buttons still escaped it.
 *
 * A minimum and not a fixed size: a sixteen-pixel icon keeps its air around
 * it instead of being clipped. And only below `sm`, because enlarging every
 * toolbar of the back office for a precision the mouse already has would be
 * paying for a problem nobody has.
 */
const sizes = {
    md: "p-1.5 min-h-7.5 min-w-7.5 justify-center sm:min-h-0 sm:min-w-0",
};

// Always project a label to assistive tech: prefer explicit ariaLabel, fall back to title.
// Components that pass neither will render an unlabelled button - caught by lint:a11y in CI.
// Computed, not read once: a button whose title follows its state (the side
// menu's "Tout replier" / "Tout déplier") kept announcing its first word
// while the tooltip moved on (07/10/2026).
const computedAriaLabel = computed(() => props.ariaLabel ?? props.title ?? undefined);
const resolvedColor = computed(() => colors[props.color] ?? colors.default);
</script>

<template>
    <component
        :is="href ? 'a' : 'button'"
        v-bind="href ? { href } : { type: 'button' }"
        :title="title"
        :aria-label="computedAriaLabel"
        class="rounded transition-colors inline-flex items-center disabled:opacity-50 disabled:cursor-not-allowed"
        :class="[sizes[size] ?? sizes.md, active ? 'text-accent-400 bg-accent-500/15' : resolvedColor.text, resolvedColor.bg]"
        :aria-pressed="active || undefined"
    >
        <slot>
            <component :is="icon" v-if="icon" class="w-4 h-4" :stroke-width="2" />
        </slot>
    </component>
</template>
