<script setup>
const props = defineProps({
    color: { type: String, default: "default" },
    // md = standard p-1.5 padding; compact = fixed w-6 h-6 for dense inline contexts
    size: { type: String, default: "md" },
    title: { type: String, default: null },
    ariaLabel: { type: String, default: null },
    href: { type: String, default: null },
});

const colors = {
    default: { text: "text-secondary hover:text-primary",    bg: "hover:bg-surface-2" },
    sky:     { text: "text-secondary hover:text-sky-400",     bg: "hover:bg-surface-2" },
    accent:  { text: "text-secondary hover:text-accent-400",  bg: "hover:bg-surface-2" },
    rose:    { text: "text-secondary hover:text-rose-400",    bg: "hover:bg-rose-500/10" },
    emerald: { text: "text-secondary hover:text-emerald-400", bg: "hover:bg-emerald-500/10" },
    amber:   { text: "text-secondary hover:text-amber-400",   bg: "hover:bg-surface-2" },
    // For use on bright, non-Aurora surfaces (post-it sticky notes, light
    // overlays, custom-colored cards). Aurora tokens (text-secondary etc.)
    // assume a dark surface and become invisible on bright backgrounds -
    // this variant ships a dark-on-light palette tuned for that case.
    "on-light": { text: "text-black/50 hover:text-black/80",  bg: "hover:bg-black/10" },
};

const sizes = {
    md:      "p-1.5",
    compact: "w-6 h-6 justify-center",
};

// Always project a label to assistive tech: prefer explicit ariaLabel, fall back to title.
// Components that pass neither will render an unlabelled button - caught by lint:a11y in CI.
const computedAriaLabel = props.ariaLabel ?? props.title ?? undefined;
const resolvedColor = colors[props.color] ?? colors.default;
</script>

<template>
    <component
        :is="href ? 'a' : 'button'"
        v-bind="href ? { href } : { type: 'button' }"
        :title="title"
        :aria-label="computedAriaLabel"
        class="rounded transition-colors inline-flex items-center disabled:opacity-50 disabled:cursor-not-allowed"
        :class="[sizes[size] ?? sizes.md, resolvedColor.text, resolvedColor.bg]"
    >
        <slot />
    </component>
</template>
